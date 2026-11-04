<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__) . '/Helpers/StandingsCalculator.php';
require_once dirname(__DIR__) . '/Helpers/Scheduler.php';

final class MatchModel
{
    /**
     * Persist schedule rows in order. Expected fields are returned by Scheduler.
     *
     * @param list<array{stage: string, group_id: ?int, round_number: int, match_number: int, home_team_id: ?int, away_team_id: ?int}> $matches
     * @return list<int> Inserted match IDs in input order.
     */
    public function insertMany(int $tournamentId, array $matches): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        if (!array_is_list($matches)) {
            throw new InvalidArgumentException('Matches must be provided as a list.');
        }

        $validatedMatches = [];
        foreach ($matches as $match) {
            if (!is_array($match)) {
                throw new InvalidArgumentException('Each match must be an associative array.');
            }

            $stage = $match['stage'] ?? null;
            $groupId = $match['group_id'] ?? null;
            $roundNumber = $match['round_number'] ?? null;
            $matchNumber = $match['match_number'] ?? null;
            $homeTeamId = $match['home_team_id'] ?? null;
            $awayTeamId = $match['away_team_id'] ?? null;

            if (!in_array($stage, ['league', 'group', 'knockout'], true)) {
                throw new InvalidArgumentException('Match stage must be league, group, or knockout.');
            }
            if (($stage === 'group') !== ($groupId !== null)
                || ($groupId !== null && (!is_int($groupId) || $groupId < 1))) {
                throw new InvalidArgumentException('Only group-stage matches may have a positive group ID.');
            }
            if (!is_int($roundNumber) || $roundNumber < 1 || $roundNumber > 32767
                || !is_int($matchNumber) || $matchNumber < 1 || $matchNumber > 32767) {
                throw new InvalidArgumentException('Round and match numbers must be positive SMALLINT values.');
            }
            foreach ([$homeTeamId, $awayTeamId] as $teamId) {
                if ($teamId !== null && (!is_int($teamId) || $teamId < 1)) {
                    throw new InvalidArgumentException('Team IDs must be positive integers or null.');
                }
            }
            if ($stage !== 'knockout' && ($homeTeamId === null || $awayTeamId === null)) {
                throw new InvalidArgumentException('League and group matches require both teams.');
            }
            if ($homeTeamId !== null && $homeTeamId === $awayTeamId) {
                throw new InvalidArgumentException('A team cannot play against itself.');
            }

            $validatedMatches[] = [
                'stage' => $stage,
                'group_id' => $groupId,
                'round_number' => $roundNumber,
                'match_number' => $matchNumber,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
            ];
        }

        $pdo = Database::getInstance()->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $insertedIds = [];
            foreach (array_chunk($validatedMatches, intdiv(60_000, 7)) as $chunk) {
                $values = [];
                $parameters = [];
                foreach ($chunk as $match) {
                    $suffix = count($values);
                    $values[] = sprintf(
                        '(:tournament_id_%1$d, :stage_%1$d, :group_id_%1$d, :round_number_%1$d, :match_number_%1$d, :home_team_id_%1$d, :away_team_id_%1$d)',
                        $suffix
                    );
                    $parameters['tournament_id_' . $suffix] = $tournamentId;
                    $parameters['stage_' . $suffix] = $match['stage'];
                    $parameters['group_id_' . $suffix] = $match['group_id'];
                    $parameters['round_number_' . $suffix] = $match['round_number'];
                    $parameters['match_number_' . $suffix] = $match['match_number'];
                    $parameters['home_team_id_' . $suffix] = $match['home_team_id'];
                    $parameters['away_team_id_' . $suffix] = $match['away_team_id'];
                }

                $insertedRows = Database::getInstance()->query(
                    'INSERT INTO matches
                        (tournament_id, stage, group_id, round_number, match_number, home_team_id, away_team_id)
                     VALUES ' . implode(', ', $values) . '
                     RETURNING id, stage, round_number, match_number',
                    $parameters
                )->fetchAll();
                $idsByPosition = [];
                foreach ($insertedRows as $insertedRow) {
                    if (!isset($insertedRow['id'], $insertedRow['stage'], $insertedRow['round_number'], $insertedRow['match_number'])
                        || !is_numeric($insertedRow['id'])) {
                        throw new RuntimeException('The database did not return inserted match positions and IDs.');
                    }
                    $key = $insertedRow['stage'] . ':' . (int) $insertedRow['round_number'] . ':' . (int) $insertedRow['match_number'];
                    $idsByPosition[$key] = (int) $insertedRow['id'];
                }
                foreach ($chunk as $match) {
                    $key = $match['stage'] . ':' . $match['round_number'] . ':' . $match['match_number'];
                    if (!isset($idsByPosition[$key])) {
                        throw new RuntimeException('The database did not return a match ID for every inserted match.');
                    }
                    $insertedIds[] = $idsByPosition[$key];
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $insertedIds;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFixtures(int $tournamentId, ?string $stage = null, ?int $groupId = null): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        if ($stage !== null && !in_array($stage, ['league', 'group', 'knockout'], true)) {
            throw new InvalidArgumentException('Unsupported match stage.');
        }
        if ($groupId !== null && $stage !== 'group') {
            throw new InvalidArgumentException('A group ID can only be used when requesting the group stage.');
        }
        if ($groupId !== null) {
            $this->validateId($groupId, 'Group ID');
        }

        $sql = 'SELECT m.id, m.tournament_id, m.stage, m.group_id, g.name AS group_name,
                       m.round_number, m.match_number,
                       m.home_team_id, home.name AS home_team_name, m.away_team_id, away.name AS away_team_name,
                       m.home_score, m.away_score, m.home_penalties, m.away_penalties
                FROM matches m
                LEFT JOIN teams home ON home.id = m.home_team_id
                LEFT JOIN teams away ON away.id = m.away_team_id
                LEFT JOIN tournament_groups g ON g.id = m.group_id
                WHERE m.tournament_id = :tournament_id';
        $parameters = ['tournament_id' => $tournamentId];

        if ($stage !== null) {
            $sql .= ' AND m.stage = :stage';
            $parameters['stage'] = $stage;
        }
        if ($groupId !== null) {
            $sql .= ' AND m.group_id = :group_id';
            $parameters['group_id'] = $groupId;
        }

        $sql .= ' ORDER BY m.stage, m.round_number, m.match_number';

        return Database::getInstance()->query($sql, $parameters)->fetchAll();
    }

    public function updateScore(
        int $tournamentId,
        int $matchId,
        ?int $homeScore,
        ?int $awayScore,
        ?int $homePenalties = null,
        ?int $awayPenalties = null
    ): bool {
        $this->validateId($tournamentId, 'Tournament ID');
        $this->validateId($matchId, 'Match ID');
        if (($homeScore === null) !== ($awayScore === null)) {
            throw new InvalidArgumentException('Both match scores must be set or cleared together.');
        }
        foreach ([$homeScore, $awayScore, $homePenalties, $awayPenalties] as $score) {
            if ($score !== null && ($score < 0 || $score > 32767)) {
                throw new InvalidArgumentException('Scores must be between 0 and 32767.');
            }
        }
        if (($homePenalties === null) !== ($awayPenalties === null)) {
            throw new InvalidArgumentException('Both penalty scores must be set or omitted together.');
        }
        if ($homeScore === null && $homePenalties !== null) {
            throw new InvalidArgumentException('Penalty scores cannot be stored without match scores.');
        }

        $pdo = Database::getInstance()->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $tournament = Database::getInstance()->query(
                'SELECT id, format
                 FROM tournaments
                 WHERE id = :tournament_id
                 FOR UPDATE',
                ['tournament_id' => $tournamentId]
            )->fetch();
            if (!is_array($tournament)) {
                throw new RuntimeException('Tournament not found for this match.');
            }

            $statement = Database::getInstance()->query(
                'SELECT id, stage, round_number, match_number, home_team_id, away_team_id,
                        home_score, away_score, home_penalties, away_penalties
                 FROM matches
                 WHERE id = :match_id AND tournament_id = :tournament_id
                 FOR UPDATE',
                [
                    'match_id' => $matchId,
                    'tournament_id' => $tournamentId,
                ]
            );
            $match = $statement->fetch();
            if (!is_array($match)) {
                throw new RuntimeException('Match not found for this tournament.');
            }
            if ($match['stage'] === 'group') {
                $knockoutCount = (int) Database::getInstance()->query(
                    'SELECT COUNT(*)
                     FROM matches
                     WHERE tournament_id = :tournament_id AND stage = :stage',
                    [
                        'tournament_id' => $tournamentId,
                        'stage' => 'knockout',
                    ]
                )->fetchColumn();
                if ($knockoutCount > 0) {
                    throw new InvalidArgumentException(
                        'Group results are locked because the knockout stage has been generated. Reset the knockout stage first.'
                    );
                }
            }
            if ($match['home_team_id'] === null || $match['away_team_id'] === null) {
                throw new InvalidArgumentException('A match cannot be scored until both teams are known.');
            }
            if ($match['stage'] === 'knockout') {
                if ($homeScore !== null && $homeScore === $awayScore) {
                    if ($homePenalties === null || $awayPenalties === null || $homePenalties === $awayPenalties) {
                        throw new InvalidArgumentException('A tied knockout match requires a non-tied penalty result.');
                    }
                } elseif ($homePenalties !== null || $awayPenalties !== null) {
                    throw new InvalidArgumentException('Penalty scores are only valid for a tied knockout match.');
                }
            } elseif ($homePenalties !== null || $awayPenalties !== null) {
                throw new InvalidArgumentException('Penalty scores are only valid for knockout matches.');
            }

            $oldWinner = self::winnerTeamId($match);
            Database::getInstance()->query(
                'UPDATE matches
                 SET home_score = :home_score,
                     away_score = :away_score,
                     home_penalties = :home_penalties,
                     away_penalties = :away_penalties
                 WHERE id = :match_id AND tournament_id = :tournament_id',
                [
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'home_penalties' => $homePenalties,
                    'away_penalties' => $awayPenalties,
                    'match_id' => $matchId,
                    'tournament_id' => $tournamentId,
                ]
            );

            $match['home_score'] = $homeScore;
            $match['away_score'] = $awayScore;
            $match['home_penalties'] = $homePenalties;
            $match['away_penalties'] = $awayPenalties;
            $newWinner = self::winnerTeamId($match);
            if ($match['stage'] === 'knockout' && $oldWinner !== $newWinner) {
                $this->replaceAdvancingWinner(
                    $tournamentId,
                    (int) $match['round_number'],
                    (int) $match['match_number'],
                    $newWinner
                );
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getStandings(int $tournamentId, ?int $groupId = null): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        if ($groupId !== null) {
            $this->validateId($groupId, 'Group ID');
        }

        $sql = 'SELECT s.team_id, s.team_name, s.group_id, g.name AS group_name,
                       s.played, s.wins, s.draws, s.losses,
                       s.goals_for, s.goals_against, s.goal_difference, s.points
                FROM v_standings s
                LEFT JOIN tournament_groups g
                  ON g.id = s.group_id AND g.tournament_id = s.tournament_id
                WHERE s.tournament_id = :tournament_id';
        $parameters = ['tournament_id' => $tournamentId];
        if ($groupId !== null) {
            $sql .= ' AND s.group_id = :group_id';
            $parameters['group_id'] = $groupId;
        }
        $sql .= ' ORDER BY g.name NULLS FIRST, s.team_id';
        $standingRows = Database::getInstance()->query($sql, $parameters)->fetchAll();
        if ($standingRows === []) {
            return [];
        }

        $matchSql = 'SELECT group_id, home_team_id, away_team_id, home_score, away_score
                     FROM matches
                     WHERE tournament_id = :tournament_id
                       AND stage IN (\'league\', \'group\')
                       AND home_score IS NOT NULL';
        $matchParameters = ['tournament_id' => $tournamentId];
        if ($groupId !== null) {
            $matchSql .= ' AND group_id = :group_id';
            $matchParameters['group_id'] = $groupId;
        }
        $matchRows = Database::getInstance()->query($matchSql, $matchParameters)->fetchAll();
        $pointsRules = Database::getInstance()->query(
            'SELECT points_win, points_draw, points_loss
             FROM tournaments
             WHERE id = :tournament_id',
            ['tournament_id' => $tournamentId]
        )->fetch();
        if (!is_array($pointsRules)) {
            throw new RuntimeException('Tournament points rules could not be loaded for standings.');
        }
        $pointsRules = [
            'points_win' => (int) $pointsRules['points_win'],
            'points_draw' => (int) $pointsRules['points_draw'],
            'points_loss' => (int) $pointsRules['points_loss'],
        ];

        $scopes = [];
        foreach ($standingRows as $row) {
            $scopeKey = $row['group_id'] === null ? 'league' : (string) $row['group_id'];
            $scopes[$scopeKey]['group_name'] = $row['group_name'];
            $scopes[$scopeKey]['rows'][] = $row;
        }
        $matchesByScope = [];
        foreach ($matchRows as $match) {
            $scopeKey = $match['group_id'] === null ? 'league' : (string) $match['group_id'];
            $matchesByScope[$scopeKey][] = $match;
        }

        $standings = [];
        foreach ($scopes as $scopeKey => $scope) {
            $orderedRows = StandingsCalculator::calculate(
                $scope['rows'],
                $matchesByScope[$scopeKey] ?? [],
                $pointsRules
            );
            foreach ($orderedRows as $row) {
                $row['group_name'] = $scope['group_name'];
                $standings[] = $row;
            }
        }

        return $standings;
    }

    public function generateKnockoutFromGroups(int $tournamentId): ?string
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $pdo = Database::getInstance()->getConnection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Cannot generate a knockout stage inside an unexpected active transaction.');
        }

        $pdo->beginTransaction();
        try {
            $tournament = Database::getInstance()->query(
                'SELECT id, format, groups_count, qualifiers_per_group
                 FROM tournaments
                 WHERE id = :tournament_id
                 FOR UPDATE',
                ['tournament_id' => $tournamentId]
            )->fetch();
            if (!is_array($tournament)) {
                throw new InvalidArgumentException('Tournament not found.');
            }
            if ($tournament['format'] !== 'groups_knockout') {
                throw new InvalidArgumentException('A knockout stage can only be generated for a groups tournament.');
            }

            $existingKnockoutCount = (int) Database::getInstance()->query(
                'SELECT COUNT(*)
                 FROM matches
                 WHERE tournament_id = :tournament_id AND stage = :stage',
                ['tournament_id' => $tournamentId, 'stage' => 'knockout']
            )->fetchColumn();
            if ($existingKnockoutCount > 0) {
                throw new InvalidArgumentException('The knockout stage has already been generated.');
            }

            $remainingGroupMatches = (int) Database::getInstance()->query(
                'SELECT COUNT(*)
                 FROM matches
                 WHERE tournament_id = :tournament_id
                   AND stage = :stage
                   AND home_score IS NULL',
                ['tournament_id' => $tournamentId, 'stage' => 'group']
            )->fetchColumn();
            if ($remainingGroupMatches > 0) {
                throw new InvalidArgumentException(sprintf(
                    '%d group matches still need a result before generating the knockout stage.',
                    $remainingGroupMatches
                ));
            }

            $groups = Database::getInstance()->query(
                'SELECT id, name
                 FROM tournament_groups
                 WHERE tournament_id = :tournament_id
                 ORDER BY name, id',
                ['tournament_id' => $tournamentId]
            )->fetchAll();
            if (count($groups) !== (int) $tournament['groups_count']) {
                throw new InvalidArgumentException('Tournament group settings do not match its saved groups.');
            }

            $qualifierData = $this->buildGroupQualifierData(
                $tournamentId,
                $groups,
                (int) $tournament['qualifiers_per_group']
            );
            $bracket = Scheduler::generateSeededKnockoutBracket($qualifierData['seeded_team_ids']);
            $bracket = $this->avoidSameGroupFirstRoundRematches(
                $bracket,
                $qualifierData['group_by_team'],
                $qualifierData['seed_tier_by_team'],
                array_slice($qualifierData['seeded_team_ids'], 0, 2)
            );
            $this->insertMany($tournamentId, $bracket);
            $pdo->commit();

            return $qualifierData['unresolved_cutoff']
                ? 'A qualification tie was unresolved; registration order decided the cutoff.'
                : null;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{id: int, name: string, qualifiers: list<array<string, mixed>>}>
     */
    public function getGroupQualifierPreview(int $tournamentId): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $tournament = Database::getInstance()->query(
            'SELECT format, groups_count, qualifiers_per_group
             FROM tournaments
             WHERE id = :tournament_id',
            ['tournament_id' => $tournamentId]
        )->fetch();
        if (!is_array($tournament) || $tournament['format'] !== 'groups_knockout') {
            return [];
        }

        $groups = Database::getInstance()->query(
            'SELECT id, name
             FROM tournament_groups
             WHERE tournament_id = :tournament_id
             ORDER BY name, id',
            ['tournament_id' => $tournamentId]
        )->fetchAll();
        if (count($groups) !== (int) $tournament['groups_count']) {
            throw new RuntimeException('Tournament group settings do not match its saved groups.');
        }

        return $this->buildGroupQualifierData(
            $tournamentId,
            $groups,
            (int) $tournament['qualifiers_per_group']
        )['preview_groups'];
    }

    /**
     * @param list<array{id: int|string, name: string}> $groups
     * @return array{
     *   preview_groups: list<array{id: int, name: string, qualifiers: list<array<string, mixed>>}>,
     *   seeded_team_ids: list<int>,
     *   group_by_team: array<int, int>,
     *   seed_tier_by_team: array<int, int>,
     *   unresolved_cutoff: bool
     * }
     */
    private function buildGroupQualifierData(int $tournamentId, array $groups, int $qualifiersPerGroup): array
    {
        $qualifiers = [];
        $groupByTeam = [];
        $seedTierByTeam = [];
        $unresolvedCutoff = false;
        $previewGroups = [];

        foreach ($groups as $groupOrder => $group) {
            $groupId = (int) $group['id'];
            $groupStandings = $this->getStandings($tournamentId, $groupId);
            if (count($groupStandings) < $qualifiersPerGroup) {
                throw new InvalidArgumentException('A group does not contain enough teams to qualify.');
            }

            $cutoff = $groupStandings[$qualifiersPerGroup - 1] ?? null;
            $firstOut = $groupStandings[$qualifiersPerGroup] ?? null;
            if (is_array($cutoff)
                && is_array($firstOut)
                && !empty($cutoff['tie_unresolved'])
                && !empty($firstOut['tie_unresolved'])) {
                $unresolvedCutoff = true;
            }

            $previewGroups[$groupOrder] = [
                'id' => $groupId,
                'name' => (string) $group['name'],
                'qualifiers' => [],
            ];
            foreach ($groupStandings as $index => $standing) {
                if ($index >= $qualifiersPerGroup) {
                    break;
                }
                $teamId = (int) $standing['team_id'];
                $qualifiers[] = [
                    'team_id' => $teamId,
                    'team_name' => (string) $standing['team_name'],
                    'tier' => $index + 1,
                    'group_order' => $groupOrder,
                    'points' => (int) $standing['points'],
                    'goal_difference' => (int) $standing['goal_difference'],
                    'goals_for' => (int) $standing['goals_for'],
                ];
                $seedTierByTeam[$teamId] = $index + 1;
                $groupByTeam[$teamId] = $groupId;
            }
        }

        if (count($qualifiers) < 2) {
            throw new InvalidArgumentException('At least two teams must qualify for the knockout stage.');
        }

        usort($qualifiers, static function (array $left, array $right): int {
            return ($left['tier'] <=> $right['tier'])
                ?: ($right['points'] <=> $left['points'])
                ?: ($right['goal_difference'] <=> $left['goal_difference'])
                ?: ($right['goals_for'] <=> $left['goals_for'])
                ?: ($left['group_order'] <=> $right['group_order']);
        });
        foreach ($qualifiers as $seedIndex => $qualifier) {
            $previewGroups[$qualifier['group_order']]['qualifiers'][] = [
                'team_id' => $qualifier['team_id'],
                'team_name' => $qualifier['team_name'],
                'group_rank' => $qualifier['tier'],
                'seed' => $seedIndex + 1,
            ];
        }

        return [
            'preview_groups' => array_values($previewGroups),
            'seeded_team_ids' => array_column($qualifiers, 'team_id'),
            'group_by_team' => $groupByTeam,
            'seed_tier_by_team' => $seedTierByTeam,
            'unresolved_cutoff' => $unresolvedCutoff,
        ];
    }

    public function resetKnockout(int $tournamentId): void
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $pdo = Database::getInstance()->getConnection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Cannot reset a knockout stage inside an unexpected active transaction.');
        }

        $pdo->beginTransaction();
        try {
            $tournament = Database::getInstance()->query(
                'SELECT id, format
                 FROM tournaments
                 WHERE id = :tournament_id
                 FOR UPDATE',
                ['tournament_id' => $tournamentId]
            )->fetch();
            if (!is_array($tournament)) {
                throw new InvalidArgumentException('Tournament not found.');
            }
            if ($tournament['format'] !== 'groups_knockout') {
                throw new InvalidArgumentException('Only a groups tournament has a resettable knockout stage.');
            }

            $scoredMatchCount = (int) Database::getInstance()->query(
                'SELECT COUNT(*)
                 FROM matches
                 WHERE tournament_id = :tournament_id
                   AND stage = :stage
                   AND home_score IS NOT NULL',
                ['tournament_id' => $tournamentId, 'stage' => 'knockout']
            )->fetchColumn();
            if ($scoredMatchCount > 0) {
                throw new InvalidArgumentException('The knockout stage cannot be reset after a knockout match has a result.');
            }

            Database::getInstance()->query(
                'DELETE FROM matches
                 WHERE tournament_id = :tournament_id AND stage = :stage',
                ['tournament_id' => $tournamentId, 'stage' => 'knockout']
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Swap same-tier teams only when it reduces first-round group rematches.
     * @param list<array<string, mixed>> $bracket
     * @param array<int, int> $groupByTeam
     * @param array<int, int> $seedTierByTeam
     * @param list<int> $protectedSeedIds
     * @return list<array<string, mixed>>
     */
    private function avoidSameGroupFirstRoundRematches(
        array $bracket,
        array $groupByTeam,
        array $seedTierByTeam,
        array $protectedSeedIds = []
    ): array {
        // Keep the top two seeds on their original paths so they can meet only in the final.
        $protectedSeeds = array_fill_keys($protectedSeedIds, true);
        $firstRoundCount = 1;
        while ($firstRoundCount < count($groupByTeam)) {
            $firstRoundCount *= 2;
        }
        $firstRoundCount = intdiv($firstRoundCount, 2);

        do {
            $changed = false;
            $currentConflicts = $this->countSameGroupPairs($bracket, $firstRoundCount, $groupByTeam);
            for ($matchIndex = 0; $matchIndex < $firstRoundCount && !$changed; $matchIndex++) {
                $homeId = $bracket[$matchIndex]['home_team_id'];
                $awayId = $bracket[$matchIndex]['away_team_id'];
                if ($homeId === null || $awayId === null
                    || $groupByTeam[$homeId] !== $groupByTeam[$awayId]) {
                    continue;
                }

                for ($otherIndex = 0; $otherIndex < $firstRoundCount && !$changed; $otherIndex++) {
                    if ($otherIndex === $matchIndex
                        || $bracket[$otherIndex]['home_team_id'] === null
                        || $bracket[$otherIndex]['away_team_id'] === null) {
                        continue;
                    }
                    foreach (['home_team_id', 'away_team_id'] as $problemSlot) {
                        foreach (['home_team_id', 'away_team_id'] as $otherSlot) {
                            $problemTeam = $bracket[$matchIndex][$problemSlot];
                            $otherTeam = $bracket[$otherIndex][$otherSlot];
                            if (isset($protectedSeeds[$problemTeam])
                                || isset($protectedSeeds[$otherTeam])
                                || $seedTierByTeam[$problemTeam] !== $seedTierByTeam[$otherTeam]) {
                                continue;
                            }
                            $bracket[$matchIndex][$problemSlot] = $otherTeam;
                            $bracket[$otherIndex][$otherSlot] = $problemTeam;
                            if ($this->countSameGroupPairs($bracket, $firstRoundCount, $groupByTeam) < $currentConflicts) {
                                $changed = true;
                                break 2;
                            }
                            $bracket[$matchIndex][$problemSlot] = $problemTeam;
                            $bracket[$otherIndex][$otherSlot] = $otherTeam;
                        }
                    }
                }
            }
        } while ($changed);

        return $bracket;
    }

    /**
     * @param list<array<string, mixed>> $bracket
     * @param array<int, int> $groupByTeam
     */
    private function countSameGroupPairs(array $bracket, int $firstRoundCount, array $groupByTeam): int
    {
        $conflicts = 0;
        for ($index = 0; $index < $firstRoundCount; $index++) {
            $homeId = $bracket[$index]['home_team_id'];
            $awayId = $bracket[$index]['away_team_id'];
            if ($homeId !== null && $awayId !== null && $groupByTeam[$homeId] === $groupByTeam[$awayId]) {
                $conflicts++;
            }
        }

        return $conflicts;
    }

    private function replaceAdvancingWinner(
        int $tournamentId,
        int $roundNumber,
        int $matchNumber,
        ?int $winnerTeamId
    ): void {
        $nextRound = $roundNumber + 1;
        $nextMatchNumber = intdiv($matchNumber + 1, 2);
        $targetSlot = $matchNumber % 2 === 1 ? 'home_team_id' : 'away_team_id';

        $statement = Database::getInstance()->query(
            'SELECT id, round_number, match_number, home_team_id, away_team_id,
                    home_score, away_score, home_penalties, away_penalties
             FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage
               AND round_number = :round_number
               AND match_number = :match_number
             FOR UPDATE',
            [
                'tournament_id' => $tournamentId,
                'stage' => 'knockout',
                'round_number' => $nextRound,
                'match_number' => $nextMatchNumber,
            ]
        );
        $nextMatch = $statement->fetch();
        if (!is_array($nextMatch)) {
            $maxRound = Database::getInstance()->query(
                'SELECT MAX(round_number)
                 FROM matches
                 WHERE tournament_id = :tournament_id AND stage = :stage',
                [
                    'tournament_id' => $tournamentId,
                    'stage' => 'knockout',
                ]
            )->fetchColumn();
            if ($maxRound !== false && (int) $maxRound >= $nextRound) {
                throw new RuntimeException('The knockout bracket is missing a required next-round match.');
            }

            return;
        }

        $currentTeamId = $nextMatch[$targetSlot] === null ? null : (int) $nextMatch[$targetSlot];
        if ($currentTeamId === $winnerTeamId) {
            return;
        }

        $existingWinner = self::winnerTeamId($nextMatch);
        if ($existingWinner !== null) {
            $this->replaceAdvancingWinner(
                $tournamentId,
                (int) $nextMatch['round_number'],
                (int) $nextMatch['match_number'],
                null
            );
        }

        if ($nextMatch['home_score'] !== null) {
            Database::getInstance()->query(
                'UPDATE matches
                 SET home_score = NULL, away_score = NULL,
                     home_penalties = NULL, away_penalties = NULL
                 WHERE id = :match_id AND tournament_id = :tournament_id',
                [
                    'match_id' => (int) $nextMatch['id'],
                    'tournament_id' => $tournamentId,
                ]
            );
        }

        $sql = sprintf(
            'UPDATE matches SET %s = :winner_team_id WHERE id = :match_id AND tournament_id = :tournament_id',
            $targetSlot
        );
        Database::getInstance()->query(
            $sql,
            [
                'winner_team_id' => $winnerTeamId,
                'match_id' => (int) $nextMatch['id'],
                'tournament_id' => $tournamentId,
            ]
        );
    }

    /** @param array<string, mixed> $match */
    private static function winnerTeamId(array $match): ?int
    {
        if ($match['home_score'] === null || $match['away_score'] === null) {
            return null;
        }

        $homeScore = (int) $match['home_score'];
        $awayScore = (int) $match['away_score'];
        if ($homeScore > $awayScore) {
            return (int) $match['home_team_id'];
        }
        if ($awayScore > $homeScore) {
            return (int) $match['away_team_id'];
        }

        if ($match['home_penalties'] === null || $match['away_penalties'] === null) {
            return null;
        }

        return (int) $match['home_penalties'] > (int) $match['away_penalties']
            ? (int) $match['home_team_id']
            : (int) $match['away_team_id'];
    }

    private function validateId(int $id, string $label): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $label));
        }
    }
}
