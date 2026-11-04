<?php

declare(strict_types=1);

final class StandingsCalculator
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $matches
     * @param array{points_win: int, points_draw: int, points_loss: int} $pointsRules
     * @return list<array<string, mixed>>
     */
    public static function calculate(array $rows, array $matches, array $pointsRules): array
    {
        foreach (['points_win', 'points_draw', 'points_loss'] as $key) {
            if (!isset($pointsRules[$key]) || !is_int($pointsRules[$key]) || $pointsRules[$key] < 0) {
                throw new InvalidArgumentException('Standings points rules must be non-negative integers.');
            }
        }

        $rowsById = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['team_id']) || !is_numeric($row['team_id'])) {
                throw new InvalidArgumentException('Every standings row must include a numeric team ID.');
            }
            $teamId = (int) $row['team_id'];
            if ($teamId < 1 || isset($rowsById[$teamId])) {
                throw new InvalidArgumentException('Standings team IDs must be positive and unique.');
            }
            $row['team_id'] = $teamId;
            foreach (['points', 'goal_difference', 'goals_for'] as $key) {
                $row[$key] = isset($row[$key]) && is_numeric($row[$key]) ? (int) $row[$key] : 0;
            }
            $rowsById[$teamId] = $row;
        }

        $ordered = array_values($rowsById);
        usort($ordered, static function (array $left, array $right): int {
            foreach (['points', 'goal_difference', 'goals_for'] as $key) {
                $comparison = $right[$key] <=> $left[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['team_id'] <=> $right['team_id'];
        });

        $result = [];
        $start = 0;
        while ($start < count($ordered)) {
            $end = $start + 1;
            while ($end < count($ordered) && self::sameOverallStats($ordered[$start], $ordered[$end])) {
                $end++;
            }

            $tiedRows = array_slice($ordered, $start, $end - $start);
            if (count($tiedRows) > 1) {
                [$tiedRows, $unresolvedIds] = self::resolveHeadToHead($tiedRows, $matches, $pointsRules);
            } else {
                $unresolvedIds = [];
            }

            foreach ($tiedRows as $row) {
                $row['position'] = count($result) + 1;
                $row['tie_unresolved'] = isset($unresolvedIds[$row['team_id']]);
                $result[] = $row;
            }
            $start = $end;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $matches
     * @param array{points_win: int, points_draw: int, points_loss: int} $pointsRules
     * @return array{list<array<string, mixed>>, array<int, true>}
     */
    private static function resolveHeadToHead(array $rows, array $matches, array $pointsRules): array
    {
        if (count($rows) < 2) {
            return [$rows, []];
        }

        $ids = array_fill_keys(array_column($rows, 'team_id'), true);
        $miniStats = [];
        foreach (array_keys($ids) as $teamId) {
            $miniStats[$teamId] = ['points' => 0, 'goal_difference' => 0, 'goals_for' => 0];
        }

        foreach ($matches as $match) {
            if (!is_array($match)
                || !is_numeric($match['home_team_id'] ?? null)
                || !is_numeric($match['away_team_id'] ?? null)
                || !is_numeric($match['home_score'] ?? null)
                || !is_numeric($match['away_score'] ?? null)) {
                continue;
            }

            $homeId = (int) $match['home_team_id'];
            $awayId = (int) $match['away_team_id'];
            if (!isset($ids[$homeId], $ids[$awayId])) {
                continue;
            }

            $homeScore = (int) $match['home_score'];
            $awayScore = (int) $match['away_score'];
            $miniStats[$homeId]['goals_for'] += $homeScore;
            $miniStats[$awayId]['goals_for'] += $awayScore;
            $miniStats[$homeId]['goal_difference'] += $homeScore - $awayScore;
            $miniStats[$awayId]['goal_difference'] += $awayScore - $homeScore;

            if ($homeScore > $awayScore) {
                $miniStats[$homeId]['points'] += $pointsRules['points_win'];
                $miniStats[$awayId]['points'] += $pointsRules['points_loss'];
            } elseif ($awayScore > $homeScore) {
                $miniStats[$awayId]['points'] += $pointsRules['points_win'];
                $miniStats[$homeId]['points'] += $pointsRules['points_loss'];
            } else {
                $miniStats[$homeId]['points'] += $pointsRules['points_draw'];
                $miniStats[$awayId]['points'] += $pointsRules['points_draw'];
            }
        }

        $statsOrdered = $rows;
        usort($statsOrdered, static function (array $left, array $right) use ($miniStats): int {
            $leftStats = $miniStats[$left['team_id']];
            $rightStats = $miniStats[$right['team_id']];
            foreach (['points', 'goal_difference', 'goals_for'] as $key) {
                $comparison = $rightStats[$key] <=> $leftStats[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['team_id'] <=> $right['team_id'];
        });

        $allEqual = true;
        for ($index = 1; $index < count($statsOrdered); $index++) {
            if (!self::sameMiniStats($statsOrdered[0], $statsOrdered[$index], $miniStats)) {
                $allEqual = false;
                break;
            }
        }
        if ($allEqual) {
            $unresolved = [];
            foreach ($statsOrdered as $row) {
                $unresolved[$row['team_id']] = true;
            }

            return [$statsOrdered, $unresolved];
        }

        $resolvedRows = [];
        $unresolved = [];
        $start = 0;
        while ($start < count($statsOrdered)) {
            $end = $start + 1;
            while ($end < count($statsOrdered)
                && self::sameMiniStats($statsOrdered[$start], $statsOrdered[$end], $miniStats)) {
                $end++;
            }

            $subset = array_slice($statsOrdered, $start, $end - $start);
            if (count($subset) > 1) {
                [$subset, $subsetUnresolved] = self::resolveHeadToHead($subset, $matches, $pointsRules);
                $unresolved += $subsetUnresolved;
            }
            array_push($resolvedRows, ...$subset);
            $start = $end;
        }

        return [$resolvedRows, $unresolved];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private static function sameOverallStats(array $left, array $right): bool
    {
        return $left['points'] === $right['points']
            && $left['goal_difference'] === $right['goal_difference']
            && $left['goals_for'] === $right['goals_for'];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param array<int, array{points: int, goal_difference: int, goals_for: int}> $miniStats
     */
    private static function sameMiniStats(array $left, array $right, array $miniStats): bool
    {
        $leftStats = $miniStats[$left['team_id']];
        $rightStats = $miniStats[$right['team_id']];

        return $leftStats['points'] === $rightStats['points']
            && $leftStats['goal_difference'] === $rightStats['goal_difference']
            && $leftStats['goals_for'] === $rightStats['goals_for'];
    }
}
