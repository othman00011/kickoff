<?php

declare(strict_types=1);

final class Scheduler
{
    /**
     * @param list<int> $teamIds
     * @return list<array{stage: string, group_id: ?int, round_number: int, match_number: int, home_team_id: int, away_team_id: int}>
     */
    public static function generateRoundRobin(
        array $teamIds,
        int $legs = 1,
        string $stage = 'league',
        ?int $groupId = null
    ): array {
        self::validateTeamIds($teamIds);
        self::validateLegs($legs);
        self::validateRoundRobinStage($stage, $groupId);

        if (count($teamIds) < 2) {
            return [];
        }

        $rotation = $teamIds;
        if (count($rotation) % 2 !== 0) {
            $rotation[] = null;
        }

        $roundCount = count($rotation) - 1;
        $matches = [];

        for ($round = 1; $round <= $roundCount; $round++) {
            $matchNumber = 1;
            $teamCount = count($rotation);

            for ($pair = 0; $pair < $teamCount / 2; $pair++) {
                $first = $rotation[$pair];
                $second = $rotation[$teamCount - 1 - $pair];
                if ($first === null || $second === null) {
                    continue;
                }

                if ($round % 2 === 1) {
                    $homeTeamId = $first;
                    $awayTeamId = $second;
                } else {
                    $homeTeamId = $second;
                    $awayTeamId = $first;
                }

                $matches[] = [
                    'stage' => $stage,
                    'group_id' => $groupId,
                    'round_number' => $round,
                    'match_number' => $matchNumber++,
                    'home_team_id' => $homeTeamId,
                    'away_team_id' => $awayTeamId,
                ];
            }

            $lastTeam = array_pop($rotation);
            array_splice($rotation, 1, 0, [$lastTeam]);
        }

        if ($legs === 2) {
            $firstLegMatches = $matches;
            foreach ($firstLegMatches as $match) {
                $matches[] = [
                    ...$match,
                    'round_number' => $match['round_number'] + $roundCount,
                    'home_team_id' => $match['away_team_id'],
                    'away_team_id' => $match['home_team_id'],
                ];
            }
        }

        return $matches;
    }

    /**
     * @param array<int, list<int>> $teamsByGroup Group ID mapped to its ordered team IDs.
     * @return list<array{stage: string, group_id: int, round_number: int, match_number: int, home_team_id: int, away_team_id: int}>
     */
    public static function generateGroupStage(array $teamsByGroup, int $legs = 1): array
    {
        self::validateLegs($legs);
        $matches = [];
        $nextMatchNumberByRound = [];

        foreach ($teamsByGroup as $groupId => $teamIds) {
            if (!is_int($groupId) || $groupId < 1 || !is_array($teamIds) || !array_is_list($teamIds)) {
                throw new InvalidArgumentException('Group schedules must map positive integer IDs to team ID lists.');
            }

            $groupMatches = self::generateRoundRobin($teamIds, $legs, 'group', $groupId);
            foreach ($groupMatches as $match) {
                $round = $match['round_number'];
                $match['match_number'] = ($nextMatchNumberByRound[$round] ?? 0) + 1;
                $nextMatchNumberByRound[$round] = $match['match_number'];
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * Creates a complete power-of-two bracket. A one-team bye is advanced immediately;
     * remaining slots stay null until an upstream match supplies a winner.
     *
     * @param list<int> $teamIds
     * @return list<array{stage: string, group_id: null, round_number: int, match_number: int, home_team_id: ?int, away_team_id: ?int}>
     */
    public static function generateKnockoutBracket(array $teamIds): array
    {
        self::validateTeamIds($teamIds);

        if (count($teamIds) < 2) {
            return [];
        }

        $bracketSize = 1;
        while ($bracketSize < count($teamIds)) {
            $bracketSize *= 2;
        }

        $roundCount = (int) log($bracketSize, 2);
        $firstRoundMatchCount = intdiv($bracketSize, 2);
        $playedFirstRoundCount = count($teamIds) - $firstRoundMatchCount;
        $firstRoundPositions = [];
        for ($position = 1; $position <= $firstRoundMatchCount; $position += 2) {
            $firstRoundPositions[] = $position;
        }
        for ($position = 2; $position <= $firstRoundMatchCount; $position += 2) {
            $firstRoundPositions[] = $position;
        }

        $rounds = [];

        for ($round = 1; $round <= $roundCount; $round++) {
            $matchCount = intdiv($bracketSize, 2 ** $round);
            $rounds[$round] = [];
            for ($matchNumber = 1; $matchNumber <= $matchCount; $matchNumber++) {
                $rounds[$round][] = [
                    'stage' => 'knockout',
                    'group_id' => null,
                    'round_number' => $round,
                    'match_number' => $matchNumber,
                    'home_team_id' => null,
                    'away_team_id' => null,
                ];
            }
        }

        $teamIndex = 0;
        foreach ($firstRoundPositions as $index => $matchNumber) {
            $isPlayedMatch = $index < $playedFirstRoundCount;
            $homeTeamId = $teamIds[$teamIndex++];
            $awayTeamId = $isPlayedMatch ? $teamIds[$teamIndex++] : null;
            $rounds[1][$matchNumber - 1]['home_team_id'] = $homeTeamId;
            $rounds[1][$matchNumber - 1]['away_team_id'] = $awayTeamId;

            if (!$isPlayedMatch && $roundCount > 1) {
                $nextMatchNumber = intdiv($matchNumber + 1, 2);
                $slot = $matchNumber % 2 === 1 ? 'home_team_id' : 'away_team_id';
                $rounds[2][$nextMatchNumber - 1][$slot] = $homeTeamId;
            }
        }

        $matches = [];
        foreach ($rounds as $roundMatches) {
            array_push($matches, ...$roundMatches);
        }

        return $matches;
    }

    /**
     * Creates a seeded bracket using the classic 1-vs-last placement.
     *
     * @param list<int> $seededTeamIds Best seed first.
     * @return list<array{stage: string, group_id: null, round_number: int, match_number: int, home_team_id: ?int, away_team_id: ?int}>
     */
    public static function generateSeededKnockoutBracket(array $seededTeamIds): array
    {
        self::validateTeamIds($seededTeamIds);
        if (count($seededTeamIds) < 2) {
            return [];
        }

        $bracketSize = 1;
        $roundCount = 0;
        while ($bracketSize < count($seededTeamIds)) {
            $bracketSize *= 2;
        }
        for ($size = $bracketSize; $size > 1; $size = intdiv($size, 2)) {
            $roundCount++;
        }

        $seedOrder = [1, 2];
        for ($size = 4; $size <= $bracketSize; $size *= 2) {
            $expandedOrder = [];
            foreach ($seedOrder as $seed) {
                $expandedOrder[] = $seed;
                $expandedOrder[] = $size + 1 - $seed;
            }
            $seedOrder = $expandedOrder;
        }

        $rounds = [];
        for ($round = 1; $round <= $roundCount; $round++) {
            $matchCount = intdiv($bracketSize, 2 ** $round);
            $rounds[$round] = [];
            for ($matchNumber = 1; $matchNumber <= $matchCount; $matchNumber++) {
                $rounds[$round][] = [
                    'stage' => 'knockout',
                    'group_id' => null,
                    'round_number' => $round,
                    'match_number' => $matchNumber,
                    'home_team_id' => null,
                    'away_team_id' => null,
                ];
            }
        }

        for ($index = 0; $index < $bracketSize; $index += 2) {
            $matchNumber = intdiv($index, 2) + 1;
            $homeSeed = $seedOrder[$index];
            $awaySeed = $seedOrder[$index + 1];
            $homeTeamId = $homeSeed <= count($seededTeamIds) ? $seededTeamIds[$homeSeed - 1] : null;
            $awayTeamId = $awaySeed <= count($seededTeamIds) ? $seededTeamIds[$awaySeed - 1] : null;

            if ($homeTeamId !== null && $awayTeamId !== null) {
                $rounds[1][$matchNumber - 1]['home_team_id'] = $homeTeamId;
                $rounds[1][$matchNumber - 1]['away_team_id'] = $awayTeamId;
                continue;
            }

            $byeTeamId = $homeTeamId ?? $awayTeamId;
            if ($byeTeamId === null) {
                continue;
            }
            $rounds[1][$matchNumber - 1]['home_team_id'] = $byeTeamId;
            if ($roundCount > 1) {
                $nextMatchNumber = intdiv($matchNumber + 1, 2);
                $slot = $matchNumber % 2 === 1 ? 'home_team_id' : 'away_team_id';
                $rounds[2][$nextMatchNumber - 1][$slot] = $byeTeamId;
            }
        }

        $matches = [];
        foreach ($rounds as $roundMatches) {
            array_push($matches, ...$roundMatches);
        }

        return $matches;
    }

    /** @param list<int> $teamIds */
    private static function validateTeamIds(array $teamIds): void
    {
        if (!array_is_list($teamIds)) {
            throw new InvalidArgumentException('Team IDs must be provided as a list.');
        }

        $seen = [];
        foreach ($teamIds as $teamId) {
            if (!is_int($teamId) || $teamId < 1 || isset($seen[$teamId])) {
                throw new InvalidArgumentException('Team IDs must be unique positive integers.');
            }
            $seen[$teamId] = true;
        }
    }

    private static function validateLegs(int $legs): void
    {
        if (!in_array($legs, [1, 2], true)) {
            throw new InvalidArgumentException('Round-robin schedules support one or two legs.');
        }
    }

    private static function validateRoundRobinStage(string $stage, ?int $groupId): void
    {
        if (!in_array($stage, ['league', 'group'], true)) {
            throw new InvalidArgumentException('Round-robin stage must be league or group.');
        }
        if (($stage === 'group') !== ($groupId !== null) || ($groupId !== null && $groupId < 1)) {
            throw new InvalidArgumentException('Group schedules require a positive group ID; league schedules must not have one.');
        }
    }
}
