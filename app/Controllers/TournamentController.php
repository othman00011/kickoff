<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Controller.php';
require_once dirname(__DIR__) . '/Models/Tournament.php';
require_once dirname(__DIR__) . '/Models/Team.php';
require_once dirname(__DIR__) . '/Models/TournamentGroup.php';
require_once dirname(__DIR__) . '/Models/MatchModel.php';
require_once dirname(__DIR__) . '/Models/LoginThrottle.php';
require_once dirname(__DIR__) . '/Helpers/Security.php';
require_once dirname(__DIR__) . '/Helpers/Scheduler.php';

final class TournamentController extends Controller
{
    private const ADMIN_SESSION_KEY = 'admin_tournament_ids';

    public function create(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($requestMethod !== 'POST') {
            if ($requestMethod !== 'GET') {
                $this->methodNotAllowed(['GET', 'POST']);
                return;
            }

            $this->render('tournament/create', [
                'flashMessage' => $this->consumeFlash(),
                'csrfTokenField' => Security::csrfField(),
            ]);
            return;
        }

        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/create');
        }

        try {
            $name = Tournament::validateName($this->postText('name'));
            $format = $this->postString('format');
            $adminPassword = $this->postText('admin_password');
            Tournament::validateAdminPassword($adminPassword);
            $teamNames = Team::validateNames($this->parseTeamNames($_POST['teams'] ?? null));
            $pointsWin = $this->postInt('points_win', 3, 0, 32767);
            $pointsDraw = $this->postInt('points_draw', 1, 0, 32767);
            $pointsLoss = $this->postInt('points_loss', 0, 0, 32767);
            $roundRobinLegs = $this->postInt('round_robin_legs', 1, 1, 2);

            if (!in_array($format, ['league', 'knockout', 'groups_knockout'], true)) {
                throw new InvalidArgumentException('Select a supported tournament format.');
            }
            $groupsCount = null;
            $qualifiersPerGroup = null;
            $groupNames = [];
            if ($format === 'groups_knockout') {
                $limits = require dirname(__DIR__, 2) . '/config/limits.php';
                $groupsCount = $this->postInt('groups_count', 0, $limits['min_groups'], $limits['max_groups']);
                $qualifiersPerGroup = $this->postInt('qualifiers_per_group', 0, 1, 32767);
                if ($groupsCount > intdiv(count($teamNames), 2)) {
                    throw new InvalidArgumentException('Each group must contain at least two teams.');
                }

                $groupNames = $this->parseGroupNames($_POST['group_names'] ?? null, $groupsCount);
                $smallestGroupSize = intdiv(count($teamNames), $groupsCount);
                if ($qualifiersPerGroup > $smallestGroupSize) {
                    throw new InvalidArgumentException('Each group cannot qualify more teams than it contains.');
                }
                if ($groupsCount * $qualifiersPerGroup < 2) {
                    throw new InvalidArgumentException('At least two teams must qualify for the knockout stage.');
                }
            } elseif (
                (isset($_POST['groups_count']) && $_POST['groups_count'] !== '')
                || (isset($_POST['qualifiers_per_group']) && $_POST['qualifiers_per_group'] !== '')
                || (isset($_POST['group_names']) && $_POST['group_names'] !== [])
            ) {
                throw new InvalidArgumentException('Group settings are only valid for a groups-then-knockout tournament.');
            }

            $database = Database::getInstance();
            $pdo = $database->getConnection();
            if ($pdo->inTransaction()) {
                throw new RuntimeException('Cannot create a tournament inside an unexpected active transaction.');
            }

            $pdo->beginTransaction();
            try {
                $tournamentModel = new Tournament();
                $tournamentId = $tournamentModel->create(
                    $name,
                    $adminPassword,
                    $format,
                    $pointsWin,
                    $pointsDraw,
                    $pointsLoss,
                    $roundRobinLegs,
                    $groupsCount,
                    $qualifiersPerGroup
                );

                $teamModel = new Team();
                $matchModel = new MatchModel();
                if ($format === 'groups_knockout') {
                    $groups = (new TournamentGroup())->createMany($tournamentId, $groupNames);
                    $teamsToInsert = [];
                    foreach ($teamNames as $index => $teamName) {
                        $group = $groups[$index % $groupsCount];
                        $teamsToInsert[] = ['name' => $teamName, 'group_id' => $group['id']];
                    }
                    $teamIds = $teamModel->insertMany($tournamentId, $teamsToInsert);
                    $teamsByGroup = array_fill_keys(array_column($groups, 'id'), []);
                    foreach ($teamIds as $index => $teamId) {
                        $groupId = $groups[$index % $groupsCount]['id'];
                        $teamsByGroup[$groupId][] = $teamId;
                    }
                    $fixtures = Scheduler::generateGroupStage($teamsByGroup, $roundRobinLegs);
                    $matchModel->insertMany($tournamentId, $fixtures);
                } else {
                    $teamsToInsert = array_map(
                        static fn (string $teamName): array => ['name' => $teamName],
                        $teamNames
                    );
                    $teamIds = $teamModel->insertMany($tournamentId, $teamsToInsert);

                    if ($format === 'league') {
                        $fixtures = Scheduler::generateRoundRobin($teamIds, $roundRobinLegs);
                    } else {
                        $fixtures = Scheduler::generateKnockoutBracket($teamIds);
                    }
                    $matchModel->insertMany($tournamentId, $fixtures);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Unable to regenerate the session ID after tournament creation.');
            }
            $_SESSION[self::ADMIN_SESSION_KEY][$tournamentId] = true;
            $this->flash('success', 'Tournament created successfully.');
            $this->redirect('/tournament/show/' . $tournamentId);
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/create');
        } catch (PDOException $exception) {
            $databaseMessage = (string) ($exception->errorInfo[2] ?? '');
            if ($exception->getCode() === '23505' && str_contains($databaseMessage, 'uq_tournaments_name')) {
                $this->flash('error', 'A tournament with this name already exists.');
                $this->redirect('/tournament/create');
            }
            error_log('Tournament creation failed due to a database constraint or query error: ' . $exception->getMessage());
            $this->flash('error', 'The tournament could not be saved. Check that its name, teams, and groups are unique and valid.');
            $this->redirect('/tournament/create');
        }
    }

    public function show(string $id): void
    {
        try {
            $tournamentId = $this->parsePositiveId($id, 'Tournament ID');
        } catch (InvalidArgumentException $exception) {
            $this->notFound($exception->getMessage());
            return;
        }

        $tournament = (new Tournament())->findById($tournamentId);
        if ($tournament === null) {
            $this->notFound('Tournament not found.');
            return;
        }

        $groups = (new TournamentGroup())->findByTournamentId($tournamentId);
        $teams = (new Team())->findByTournamentId($tournamentId);
        $standings = (new MatchModel())->getStandings($tournamentId);
        $fixtures = (new MatchModel())->getFixtures($tournamentId);

        $this->render('tournament/show', [
            'tournament' => $tournament,
            'groups' => $groups,
            'teams' => $teams,
            'standings' => $standings,
            'fixtures' => $fixtures,
            'flashMessage' => $this->consumeFlash(),
        ]);
    }

    public function admin(string $id): void
    {
        try {
            $tournamentId = $this->parsePositiveId($id, 'Tournament ID');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/login');
        }

        if (!$this->isAdminAuthenticated($tournamentId)) {
            $this->flash('error', 'Log in with this tournament’s admin password to manage it.');
            $this->redirect('/tournament/login');
        }

        $tournament = (new Tournament())->findById($tournamentId);
        if ($tournament === null) {
            unset($_SESSION[self::ADMIN_SESSION_KEY][$tournamentId]);
            $this->notFound('Tournament not found.');
            return;
        }

        $matchModel = new MatchModel();
        $format = (string) ($tournament['format'] ?? '');
        $groups = (new TournamentGroup())->findByTournamentId($tournamentId);
        $teams = (new Team())->findByTournamentId($tournamentId);
        $standings = $matchModel->getStandings($tournamentId);
        $fixtures = $matchModel->getFixtures($tournamentId);
        $remainingGroupMatches = 0;
        $knockoutMatches = [];
        foreach ($fixtures as $fixture) {
            if ($fixture['stage'] === 'group' && $fixture['home_score'] === null) {
                $remainingGroupMatches++;
            }
            if ($fixture['stage'] === 'knockout') {
                $knockoutMatches[] = $fixture;
            }
        }
        $knockoutHasScores = false;
        foreach ($knockoutMatches as $fixture) {
            if ($fixture['home_score'] !== null) {
                $knockoutHasScores = true;
                break;
            }
        }
        $qualifierPreview = $format === 'groups_knockout'
            && $remainingGroupMatches === 0
            && $knockoutMatches === []
                ? $matchModel->getGroupQualifierPreview($tournamentId)
                : [];

        header('Cache-Control: no-store');
        $this->render('tournament/admin', [
            'tournament' => $tournament,
            'groups' => $groups,
            'teams' => $teams,
            'standings' => $standings,
            'fixtures' => $fixtures,
            'remainingGroupMatches' => $remainingGroupMatches,
            'knockoutGenerated' => $knockoutMatches !== [],
            'knockoutHasScores' => $knockoutHasScores,
            'qualifierPreview' => $qualifierPreview,
            'csrfTokenField' => Security::csrfField(),
            'flashMessage' => $this->consumeFlash(),
        ]);
    }

    public function generateKnockout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->methodNotAllowed(['POST']);
            return;
        }
        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/login');
        }

        try {
            $tournamentId = $this->parsePostId('tournament_id', 'Tournament ID');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/login');
        }
        if (!$this->isAdminAuthenticated($tournamentId)) {
            $this->flash('error', 'Sign in to this tournament before generating its knockout stage.');
            $this->redirect('/tournament/login');
        }

        try {
            $warning = (new MatchModel())->generateKnockoutFromGroups($tournamentId);
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/admin/' . $tournamentId);
        }

        if ($warning !== null) {
            $this->flash('info', $warning);
        } else {
            $this->flash('success', 'Knockout bracket generated successfully.');
        }
        $this->redirect('/tournament/admin/' . $tournamentId);
    }

    public function resetKnockout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->methodNotAllowed(['POST']);
            return;
        }
        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/login');
        }

        try {
            $tournamentId = $this->parsePostId('tournament_id', 'Tournament ID');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/login');
        }
        if (!$this->isAdminAuthenticated($tournamentId)) {
            $this->flash('error', 'Sign in to this tournament before resetting its knockout stage.');
            $this->redirect('/tournament/login');
        }

        try {
            (new MatchModel())->resetKnockout($tournamentId);
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/admin/' . $tournamentId);
        }

        $this->flash('success', 'Knockout stage reset. Group results can be edited again.');
        $this->redirect('/tournament/admin/' . $tournamentId);
    }

    public function login(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($requestMethod !== 'POST') {
            if ($requestMethod !== 'GET') {
                $this->methodNotAllowed(['GET', 'POST']);
                return;
            }

            header('Cache-Control: no-store');
            $this->render('tournament/login', [
                'flashMessage' => $this->consumeFlash(),
                'csrfTokenField' => Security::csrfField(),
            ]);
            return;
        }

        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/login');
        }

        $name = $_POST['name'] ?? '';
        $password = $_POST['password'] ?? '';
        $name = is_string($name) ? trim($name) : '';
        $password = is_string($password) ? $password : '';
        try {
            $name = Tournament::validateName($name);
        } catch (InvalidArgumentException) {
            $name = '';
        }

        $throttle = new LoginThrottle();
        $this->purgeLoginAttemptsOccasionally($throttle);
        $ipAddress = LoginThrottle::clientIpAddress();
        $nameKey = LoginThrottle::normalizeNameKey($name);
        $retryAfter = $throttle->isBlocked($ipAddress, $nameKey);
        if ($retryAfter !== null) {
            $this->respondTooManyRequests($retryAfter);
            return;
        }

        $tournamentModel = new Tournament();
        if (!$tournamentModel->verifyAdminPassword($name, $password)) {
            $throttle->recordFailure($ipAddress, $nameKey);
            $this->flash('error', 'Tournament name or admin password is incorrect.');
            $this->redirect('/tournament/login');
        }

        $throttle->clear($ipAddress, $nameKey);
        $tournament = $tournamentModel->findByName($name);
        if ($tournament === null || !isset($tournament['id'])) {
            throw new RuntimeException('Admin password verified, but the tournament could not be loaded.');
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session ID after admin login.');
        }
        $_SESSION[self::ADMIN_SESSION_KEY][(int) $tournament['id']] = true;
        $this->flash('success', 'You are now signed in to manage this tournament.');
        $this->redirect('/tournament/admin/' . (int) $tournament['id']);
    }

    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->methodNotAllowed(['POST']);
            return;
        }
        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/login');
        }

        unset($_SESSION[self::ADMIN_SESSION_KEY]);
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session ID after admin logout.');
        }

        $this->flash('success', 'You have been signed out.');
        $this->redirect('/');
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->methodNotAllowed(['POST']);
            return;
        }
        if (!Security::validatePostToken()) {
            $this->flash('error', 'Your form session expired. Please try again.');
            $this->redirect('/tournament/login');
        }

        $idValue = $_POST['tournament_id'] ?? null;
        if (!is_string($idValue)) {
            $this->flash('error', 'A valid tournament ID is required.');
            $this->redirect('/tournament/login');
        }
        try {
            $tournamentId = $this->parsePositiveId($idValue, 'Tournament ID');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/login');
        }

        if (!$this->isAdminAuthenticated($tournamentId)) {
            $this->flash('error', 'You are not signed in to manage this tournament.');
            $this->redirect('/tournament/login');
        }

        $password = $_POST['admin_password'] ?? null;
        $tournamentModel = new Tournament();
        $tournament = $tournamentModel->findById($tournamentId);
        if ($tournament === null) {
            $this->flash('error', 'The tournament could not be found.');
            $this->redirect('/tournament/login');
        }

        $throttle = new LoginThrottle();
        $this->purgeLoginAttemptsOccasionally($throttle);
        $ipAddress = LoginThrottle::clientIpAddress();
        $nameKey = LoginThrottle::normalizeNameKey((string) $tournament['name']);
        $retryAfter = $throttle->isBlocked($ipAddress, $nameKey);
        if ($retryAfter !== null) {
            $this->respondTooManyRequests($retryAfter);
            return;
        }

        $password = $_POST['admin_password'] ?? '';
        $password = is_string($password) ? $password : '';
        if (!$tournamentModel->verifyAdminPassword((string) $tournament['name'], $password)) {
            $throttle->recordFailure($ipAddress, $nameKey);
            $this->flash('error', 'The admin password is incorrect.');
            $this->redirect('/tournament/admin/' . $tournamentId);
        }

        $throttle->clear($ipAddress, $nameKey);
        if (!$tournamentModel->deleteById($tournamentId)) {
            $this->flash('error', 'Tournament was not found or could not be deleted.');
            $this->redirect('/tournament/admin/' . $tournamentId);
        }

        unset($_SESSION[self::ADMIN_SESSION_KEY][$tournamentId]);
        $this->flash('success', 'Tournament and its related data were deleted.');
        $this->redirect('/');
    }

    /**
     * @return list<string>
     */
    private function parseTeamNames(mixed $submitted): array
    {
        if (is_string($submitted)) {
            $limits = require dirname(__DIR__, 2) . '/config/limits.php';
            if (strlen($submitted) > $limits['max_team_input_bytes']) {
                throw new InvalidArgumentException('The team list must not exceed 20 KB.');
            }
            $submitted = preg_split('/\R/u', $submitted);
            if ($submitted === false) {
                throw new InvalidArgumentException('Team list contains invalid text.');
            }
        }
        if (!is_array($submitted) || !array_is_list($submitted)) {
            throw new InvalidArgumentException('Provide teams as a list of names.');
        }
        $limits = require dirname(__DIR__, 2) . '/config/limits.php';
        if (count($submitted) > $limits['max_team_input_lines']) {
            throw new InvalidArgumentException(sprintf(
                'The team list can contain at most %d lines.',
                $limits['max_team_input_lines']
            ));
        }
        $inputBytes = 0;
        foreach ($submitted as $line) {
            if (is_string($line)) {
                $inputBytes += strlen($line);
                if ($inputBytes > $limits['max_team_input_bytes']) {
                    throw new InvalidArgumentException('The team list must not exceed 20 KB.');
                }
            }
        }

        $names = [];
        foreach ($submitted as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Each team name must be text.');
            }
            if (trim($name) !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function parseGroupNames(mixed $submitted, int $groupsCount): array
    {
        if ($submitted === null || $submitted === '') {
            $names = [];
            for ($index = 0; $index < $groupsCount; $index++) {
                $names[] = $this->alphabeticGroupName($index);
            }
            return $names;
        }
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) !== $groupsCount) {
            throw new InvalidArgumentException('Provide exactly one name for each group.');
        }

        $names = [];
        foreach ($submitted as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Group names must not be empty.');
            }
            $names[] = trim($name);
        }

        return $names;
    }

    private function alphabeticGroupName(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function postString(string $key): string
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Form field "%s" must be text.', $key));
        }

        return trim($value);
    }

    private function parsePostId(string $key, string $label): int
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be provided.', $label));
        }

        return $this->parsePositiveId($value, $label);
    }

    private function postText(string $key): string
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Form field "%s" must be text.', $key));
        }

        return $value;
    }

    private function postInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $_POST[$key] ?? (string) $default;
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            throw new InvalidArgumentException(sprintf('Form field "%s" must be a whole number.', $key));
        }

        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Form field "%s" must be between %d and %d.',
                $key,
                $minimum,
                $maximum
            ));
        }

        return $number;
    }

    private function isAdminAuthenticated(int $tournamentId): bool
    {
        return isset($_SESSION[self::ADMIN_SESSION_KEY][$tournamentId])
            && $_SESSION[self::ADMIN_SESSION_KEY][$tournamentId] === true;
    }

    private function purgeLoginAttemptsOccasionally(LoginThrottle $throttle): void
    {
        if (random_int(1, 100) === 1) {
            $throttle->purgeOld();
        }
    }

    private function respondTooManyRequests(int $retryAfter): void
    {
        $retryAfter = max(1, $retryAfter);
        header('Retry-After: ' . $retryAfter);
        $this->render('errors/429', [
            'retryMinutes' => max(1, (int) ceil($retryAfter / 60)),
        ], 429);
    }

}
