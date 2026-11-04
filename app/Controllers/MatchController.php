<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Controller.php';
require_once dirname(__DIR__) . '/Models/MatchModel.php';
require_once dirname(__DIR__) . '/Helpers/Security.php';

final class MatchController extends Controller
{
    private const ADMIN_SESSION_KEY = 'admin_tournament_ids';

    public function updateScore(): void
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
            $matchId = $this->parsePostId('match_id', 'Match ID');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/login');
        }

        if (!isset($_SESSION[self::ADMIN_SESSION_KEY][$tournamentId])
            || $_SESSION[self::ADMIN_SESSION_KEY][$tournamentId] !== true) {
            $this->flash('error', 'Sign in to this tournament before updating scores.');
            $this->redirect('/tournament/login');
        }

        try {
            $homeScore = $this->optionalScore('home_score');
            $awayScore = $this->optionalScore('away_score');
            $homePenalties = $this->optionalScore('home_penalties');
            $awayPenalties = $this->optionalScore('away_penalties');

            (new MatchModel())->updateScore(
                $tournamentId,
                $matchId,
                $homeScore,
                $awayScore,
                $homePenalties,
                $awayPenalties
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/tournament/admin/' . $tournamentId);
        }

        $this->flash('success', 'Match result updated.');
        $this->redirect('/tournament/admin/' . $tournamentId);
    }

    private function parsePostId(string $key, string $label): int
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be provided.', $label));
        }

        return $this->parsePositiveId($value, $label);
    }

    private function optionalScore(string $key): ?int
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a whole number.', $key));
        }
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $value) || (int) $value > 32767) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 32767.', $key));
        }

        return (int) $value;
    }
}
