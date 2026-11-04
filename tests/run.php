<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/App.php';
require_once dirname(__DIR__) . '/app/Helpers/Scheduler.php';
require_once dirname(__DIR__) . '/app/Helpers/StandingsCalculator.php';
require_once dirname(__DIR__) . '/app/Models/Tournament.php';
require_once dirname(__DIR__) . '/app/Models/Team.php';
require_once dirname(__DIR__) . '/app/Models/LoginThrottle.php';
require_once dirname(__DIR__) . '/app/Models/MatchModel.php';

final class RouterTestController
{
    public function show(string $id): void
    {
    }

    public function optional(string $id = ''): void
    {
    }

    public function integer(int $id): void
    {
    }
}

function testAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            "Expected %s, got %s.",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function testAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testAssertThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        if (str_contains($exception->getMessage(), $messagePart)) {
            return;
        }
        throw new RuntimeException(sprintf(
            'Expected an error containing "%s", got "%s".',
            $messagePart,
            $exception->getMessage()
        ));
    }

    throw new RuntimeException(sprintf('Expected an error containing "%s".', $messagePart));
}

function schedulerSummary(array $matches): array
{
    return array_map(
        static fn (array $match): array => [
            $match['round_number'],
            $match['match_number'],
            $match['home_team_id'],
            $match['away_team_id'],
        ],
        $matches
    );
}

$tests = [];
$addTest = static function (string $name, callable $callback) use (&$tests): void {
    $tests[] = [$name, $callback];
};

$roundRobinGoldens = [
    4 => [
        [1, 1, 1, 4], [1, 2, 2, 3], [2, 1, 3, 1],
        [2, 2, 2, 4], [3, 1, 1, 2], [3, 2, 3, 4],
    ],
    5 => [
        [1, 1, 2, 5], [1, 2, 3, 4], [2, 1, 5, 1], [2, 2, 3, 2],
        [3, 1, 1, 4], [3, 2, 5, 3], [4, 1, 3, 1], [4, 2, 2, 4],
        [5, 1, 1, 2], [5, 2, 4, 5],
    ],
    6 => [
        [1, 1, 1, 6], [1, 2, 2, 5], [1, 3, 3, 4], [2, 1, 5, 1],
        [2, 2, 4, 6], [2, 3, 3, 2], [3, 1, 1, 4], [3, 2, 5, 3],
        [3, 3, 6, 2], [4, 1, 3, 1], [4, 2, 2, 4], [4, 3, 6, 5],
        [5, 1, 1, 2], [5, 2, 3, 6], [5, 3, 4, 5],
    ],
];

$knockoutGoldens = [
    4 => [[1, 1, 1, 2], [1, 2, 3, 4], [2, 1, null, null]],
    5 => [
        [1, 1, 1, 2], [1, 2, 4, null], [1, 3, 3, null], [1, 4, 5, null],
        [2, 1, null, 4], [2, 2, 3, 5], [3, 1, null, null],
    ],
    6 => [
        [1, 1, 1, 2], [1, 2, 5, null], [1, 3, 3, 4], [1, 4, 6, null],
        [2, 1, null, 5], [2, 2, null, 6], [3, 1, null, null],
    ],
];

$addTest('round-robin schedules preserve golden fixtures', static function () use ($roundRobinGoldens): void {
    foreach ($roundRobinGoldens as $teamCount => $expected) {
        testAssertSame($expected, schedulerSummary(Scheduler::generateRoundRobin(range(1, $teamCount))));
    }
});

$addTest('pure knockout brackets preserve golden fixtures', static function () use ($knockoutGoldens): void {
    foreach ($knockoutGoldens as $teamCount => $expected) {
        testAssertSame($expected, schedulerSummary(Scheduler::generateKnockoutBracket(range(1, $teamCount))));
    }
});

$addTest('seeded brackets keep seeds one and two apart until the final', static function (): void {
    foreach ([2, 3, 4, 5, 6, 7, 8, 12, 16, 32, 64] as $teamCount) {
        $teamIds = range(1, $teamCount);
        $matches = Scheduler::generateSeededKnockoutBracket($teamIds);
        $bracketSize = 1;
        $roundCount = 0;
        while ($bracketSize < $teamCount) {
            $bracketSize *= 2;
        }
        for ($size = $bracketSize; $size > 1; $size = intdiv($size, 2)) {
            $roundCount++;
        }

        $firstRound = array_values(array_filter(
            $matches,
            static fn (array $match): bool => $match['round_number'] === 1
        ));
        testAssertSame(intdiv($bracketSize, 2), count($firstRound));
        $firstRoundTeams = [];
        $byeTeams = [];
        foreach ($firstRound as $match) {
            foreach (['home_team_id', 'away_team_id'] as $slot) {
                if ($match[$slot] !== null) {
                    $firstRoundTeams[] = $match[$slot];
                }
            }
            if ($match['away_team_id'] === null && $match['home_team_id'] !== null) {
                $byeTeams[] = $match['home_team_id'];
                $nextRoundMatch = intdiv($match['match_number'] + 1, 2);
                $slot = $match['match_number'] % 2 === 1 ? 'home_team_id' : 'away_team_id';
                $nextRound = array_values(array_filter(
                    $matches,
                    static fn (array $row): bool => $row['round_number'] === 2
                        && $row['match_number'] === $nextRoundMatch
                ));
                testAssertSame($match['home_team_id'], $nextRound[0][$slot] ?? null);
            }
        }

        sort($firstRoundTeams);
        testAssertSame($teamIds, $firstRoundTeams, 'Every seed must occur exactly once in round one.');
        sort($byeTeams);
        $expectedByes = array_slice($teamIds, 0, $bracketSize - $teamCount);
        sort($expectedByes);
        testAssertSame($expectedByes, $byeTeams, 'Byes must go to the best seeds.');

        $route = [];
        foreach ([1, 2] as $seed) {
            $route[$seed] = [];
            foreach ($firstRound as $match) {
                if ($match['home_team_id'] === $seed || $match['away_team_id'] === $seed) {
                    $matchNumber = $match['match_number'];
                    for ($round = 1; $round <= $roundCount; $round++) {
                        $route[$seed][$round] = $matchNumber;
                        $matchNumber = intdiv($matchNumber + 1, 2);
                    }
                    break;
                }
            }
        }
        for ($round = 1; $round < $roundCount; $round++) {
            testAssertTrue(
                $route[1][$round] !== $route[2][$round],
                'Seeds one and two must not meet before the final.'
            );
        }
        testAssertSame(1, $route[1][$roundCount]);
        testAssertSame(1, $route[2][$roundCount]);
    }
});

$addTest('first-round rematch avoidance swaps only same-tier qualifiers', static function (): void {
    $bracket = Scheduler::generateSeededKnockoutBracket(range(1, 8));
    $groupByTeam = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 2, 6 => 4, 7 => 3, 8 => 1];
    $seedTierByTeam = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 2, 7 => 2, 8 => 2];
    $method = new ReflectionMethod(MatchModel::class, 'avoidSameGroupFirstRoundRematches');
    $method->setAccessible(true);
    $adjusted = $method->invoke(new MatchModel(), $bracket, $groupByTeam, $seedTierByTeam, [1, 2]);
    $firstRound = array_values(array_filter(
        $adjusted,
        static fn (array $match): bool => $match['round_number'] === 1
    ));

    foreach ($firstRound as $match) {
        testAssertTrue(
            $groupByTeam[$match['home_team_id']] !== $groupByTeam[$match['away_team_id']],
            'A same-group first-round matchup should be avoided when a same-tier swap exists.'
        );
        testAssertTrue(
            $seedTierByTeam[$match['home_team_id']] !== $seedTierByTeam[$match['away_team_id']],
            'A first-round swap must keep the original seed tiers paired.'
        );
    }
    foreach ([1, 2] as $protectedSeed) {
        $originalMatch = array_values(array_filter(
            $bracket,
            static fn (array $match): bool => $match['round_number'] === 1
                && in_array($protectedSeed, [$match['home_team_id'], $match['away_team_id']], true)
        ))[0];
        $adjustedMatch = array_values(array_filter(
            $firstRound,
            static fn (array $match): bool => in_array($protectedSeed, [$match['home_team_id'], $match['away_team_id']], true)
        ))[0];
        testAssertSame($originalMatch['match_number'], $adjustedMatch['match_number']);
    }
});

$addTest('group qualifiers avoid rematches and seed byes in representative formats', static function (): void {
    $method = new ReflectionMethod(MatchModel::class, 'avoidSameGroupFirstRoundRematches');
    $method->setAccessible(true);

    $cases = [
        [
            'seeds' => [101, 201, 102, 202],
            'groups' => [101 => 1, 201 => 2, 102 => 1, 202 => 2],
            'tiers' => [101 => 1, 201 => 1, 102 => 2, 202 => 2],
            'expected_first_round' => [[101, 202], [201, 102]],
            'expected_byes' => [],
        ],
        [
            'seeds' => [101, 201, 301, 401, 102, 202, 302, 402],
            'groups' => [
                101 => 1, 201 => 2, 301 => 3, 401 => 4,
                102 => 1, 202 => 2, 302 => 3, 402 => 4,
            ],
            'tiers' => [
                101 => 1, 201 => 1, 301 => 1, 401 => 1,
                102 => 2, 202 => 2, 302 => 2, 402 => 2,
            ],
            'expected_first_round' => null,
            'expected_byes' => [],
        ],
        [
            'seeds' => [101, 201, 301, 102, 202, 302],
            'groups' => [101 => 1, 201 => 2, 301 => 3, 102 => 1, 202 => 2, 302 => 3],
            'tiers' => [101 => 1, 201 => 1, 301 => 1, 102 => 2, 202 => 2, 302 => 2],
            'expected_first_round' => null,
            'expected_byes' => [101, 201],
        ],
    ];

    foreach ($cases as $case) {
        $bracket = Scheduler::generateSeededKnockoutBracket($case['seeds']);
        $bracket = $method->invoke(
            new MatchModel(),
            $bracket,
            $case['groups'],
            $case['tiers'],
            array_slice($case['seeds'], 0, 2)
        );
        $firstRound = array_values(array_filter(
            $bracket,
            static fn (array $match): bool => $match['round_number'] === 1
        ));
        $byeTeams = [];
        $firstRoundPairs = [];
        foreach ($firstRound as $match) {
            if ($match['away_team_id'] === null) {
                $byeTeams[] = $match['home_team_id'];
                continue;
            }
            testAssertTrue(
                $case['groups'][$match['home_team_id']] !== $case['groups'][$match['away_team_id']],
                'A same-group qualifier pairing should be swapped when a same-tier alternative exists.'
            );
            $firstRoundPairs[] = [$match['home_team_id'], $match['away_team_id']];
        }
        testAssertSame($case['expected_byes'], $byeTeams);
        if ($case['expected_first_round'] !== null) {
            testAssertSame($case['expected_first_round'], $firstRoundPairs);
        }
        if ($case['expected_byes'] !== []) {
            $secondRound = array_values(array_filter(
                $bracket,
                static fn (array $match): bool => $match['round_number'] === 2
            ));
            testAssertTrue(
                in_array(101, [$secondRound[0]['home_team_id'], $secondRound[0]['away_team_id']], true),
                'Seed one must advance directly into its round-two slot.'
            );
            testAssertTrue(
                in_array(201, [$secondRound[1]['home_team_id'], $secondRound[1]['away_team_id']], true),
                'Seed two must advance directly into its round-two slot.'
            );
        }
    }
});

$pointsRules = ['points_win' => 3, 'points_draw' => 1, 'points_loss' => 0];

$addTest('head-to-head result breaks otherwise equal overall stats', static function () use ($pointsRules): void {
    $rows = [
        ['team_id' => 1, 'points' => 3, 'goal_difference' => 0, 'goals_for' => 1],
        ['team_id' => 2, 'points' => 3, 'goal_difference' => 0, 'goals_for' => 1],
    ];
    $matches = [
        ['home_team_id' => 1, 'away_team_id' => 2, 'home_score' => 1, 'away_score' => 0],
    ];
    $result = StandingsCalculator::calculate($rows, $matches, $pointsRules);
    testAssertSame([1, 2], array_column($result, 'team_id'));
    testAssertSame([1, 2], array_column($result, 'position'));
    testAssertSame([false, false], array_column($result, 'tie_unresolved'));
});

$addTest('cyclic head-to-head tie is marked and falls back to team ID', static function () use ($pointsRules): void {
    $rows = [
        ['team_id' => 3, 'points' => 3, 'goal_difference' => 0, 'goals_for' => 1],
        ['team_id' => 1, 'points' => 3, 'goal_difference' => 0, 'goals_for' => 1],
        ['team_id' => 2, 'points' => 3, 'goal_difference' => 0, 'goals_for' => 1],
    ];
    $matches = [
        ['home_team_id' => 1, 'away_team_id' => 2, 'home_score' => 1, 'away_score' => 0],
        ['home_team_id' => 2, 'away_team_id' => 3, 'home_score' => 1, 'away_score' => 0],
        ['home_team_id' => 3, 'away_team_id' => 1, 'home_score' => 1, 'away_score' => 0],
    ];
    $result = StandingsCalculator::calculate($rows, $matches, $pointsRules);
    testAssertSame([1, 2, 3], array_column($result, 'team_id'));
    testAssertSame([true, true, true], array_column($result, 'tie_unresolved'));
});

$addTest('head-to-head is recalculated for a remaining tied subset', static function () use ($pointsRules): void {
    $rows = [
        ['team_id' => 1, 'points' => 4, 'goal_difference' => 0, 'goals_for' => 3],
        ['team_id' => 2, 'points' => 4, 'goal_difference' => 0, 'goals_for' => 3],
        ['team_id' => 3, 'points' => 4, 'goal_difference' => 0, 'goals_for' => 3],
    ];
    $matches = [
        ['home_team_id' => 1, 'away_team_id' => 2, 'home_score' => 2, 'away_score' => 1],
        ['home_team_id' => 2, 'away_team_id' => 3, 'home_score' => 1, 'away_score' => 0],
        ['home_team_id' => 3, 'away_team_id' => 1, 'home_score' => 2, 'away_score' => 1],
        ['home_team_id' => 1, 'away_team_id' => 4, 'home_score' => 0, 'away_score' => 0],
        ['home_team_id' => 2, 'away_team_id' => 4, 'home_score' => 1, 'away_score' => 1],
        ['home_team_id' => 3, 'away_team_id' => 4, 'home_score' => 1, 'away_score' => 1],
    ];
    $result = StandingsCalculator::calculate($rows, $matches, $pointsRules);
    testAssertSame([1, 2, 3], array_column($result, 'team_id'));
    testAssertSame([false, false, false], array_column($result, 'tie_unresolved'));
});

$addTest('unplayed teams remain ordered by ID without division errors', static function () use ($pointsRules): void {
    $rows = [
        ['team_id' => 8, 'played' => 0, 'points' => 0, 'goal_difference' => 0, 'goals_for' => 0],
        ['team_id' => 2, 'played' => 0, 'points' => 0, 'goal_difference' => 0, 'goals_for' => 0],
        ['team_id' => 5, 'played' => 0, 'points' => 0, 'goal_difference' => 0, 'goals_for' => 0],
    ];
    $result = StandingsCalculator::calculate($rows, [], $pointsRules);
    testAssertSame([2, 5, 8], array_column($result, 'team_id'));
    testAssertSame([1, 2, 3], array_column($result, 'position'));
    testAssertSame([true, true, true], array_column($result, 'tie_unresolved'));
});

$addTest('a team with no played matches is handled safely', static function () use ($pointsRules): void {
    $row = ['team_id' => 1, 'played' => 0, 'points' => 0, 'goal_difference' => 0, 'goals_for' => 0];
    $result = StandingsCalculator::calculate([$row], [], $pointsRules);
    testAssertSame(0, $result[0]['played']);
    testAssertSame(1, $result[0]['position']);
    testAssertSame(false, $result[0]['tie_unresolved']);
});

$addTest('input limits and validation are explicit', static function (): void {
    testAssertSame(['Alpha', 'Béta'], Team::validateNames([' Alpha ', 'Béta']));
    testAssertSame('Cup Name', Tournament::validateName(' Cup Name '));
    Tournament::validateAdminPassword(str_repeat('x', 8));
    Tournament::validateAdminPassword(str_repeat('x', 72));
    testAssertThrows(
        static fn () => Team::validateNames(array_map(static fn (int $id): string => 'Team ' . $id, range(1, 65))),
        'at most 64'
    );
    testAssertThrows(static fn () => Team::validateNames(['Raja', 'raja']), 'Duplicate team name');
    testAssertThrows(static fn () => Team::validateName("A" . chr(0x7f)), 'control characters');
    testAssertThrows(static fn () => Team::validateName("\tAlpha"), 'control characters');
    testAssertThrows(static fn () => Team::validateName("\xC3\x28"), 'valid UTF-8');
    testAssertThrows(static fn () => Tournament::validateName(str_repeat('x', 101)), 'between 1 and 100');
    testAssertThrows(static fn () => Tournament::validateName("\nCup"), 'control characters');
    testAssertThrows(static fn () => Tournament::validateAdminPassword('short'), 'between 8 and 72 bytes');
    testAssertThrows(static fn () => Tournament::validateAdminPassword(str_repeat('x', 73)), 'between 8 and 72 bytes');
    testAssertThrows(static fn () => Tournament::validateAdminPassword("pass\0word"), 'null bytes');
});

$addTest('LIKE wildcard characters are escaped literally', static function (): void {
    $slash = chr(92);
    testAssertSame(
        '100' . $slash . '%' . $slash . '_' . $slash . $slash,
        Tournament::escapeLike('100%_' . $slash)
    );
});

$addTest('router validates argument count, types, and URL segment limits', static function (): void {
    $show = new ReflectionMethod(RouterTestController::class, 'show');
    $optional = new ReflectionMethod(RouterTestController::class, 'optional');
    $integer = new ReflectionMethod(RouterTestController::class, 'integer');
    testAssertTrue(App::argumentsAreValid($show, ['123']), 'A valid string ID should be accepted.');
    testAssertTrue(!App::argumentsAreValid($show, []), 'A missing required ID should be rejected.');
    testAssertTrue(!App::argumentsAreValid($show, ['1', '2']), 'An extra ID should be rejected.');
    testAssertTrue(!App::argumentsAreValid($show, [str_repeat('x', 201)]), 'Oversized segments should be rejected.');
    testAssertTrue(!App::argumentsAreValid($show, ["bad\npath"]), 'Control characters should be rejected.');
    testAssertTrue(!App::argumentsAreValid($integer, ['123']), 'Non-string route binding should be rejected.');
    testAssertTrue(App::argumentsAreValid($optional, []), 'Optional route arguments should be accepted.');
});

$failures = 0;
foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    }
}

echo sprintf('%d tests, %d failures%s', count($tests), $failures, PHP_EOL);
exit($failures === 0 ? 0 : 1);
