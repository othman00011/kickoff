<?php
declare(strict_types=1);

$tournament = isset($tournament) && is_array($tournament) ? $tournament : [];
$groups = isset($groups) && is_array($groups) ? $groups : [];
$teams = isset($teams) && is_array($teams) ? $teams : [];
$standings = isset($standings) && is_array($standings) ? $standings : [];
$fixtures = isset($fixtures) && is_array($fixtures) ? $fixtures : [];
$tournamentId = (int) ($tournament['id'] ?? 0);
$format = (string) ($tournament['format'] ?? '');
$formatLabels = ['league' => 'League', 'knockout' => 'Knockout', 'groups_knockout' => 'Groups + knockout'];
$stageLabels = ['league' => 'League stage', 'group' => 'Group stage', 'knockout' => 'Knockout stage'];
$renderStandingsTable = static function (array $rows) use ($escape): string {
    ob_start();
    ?>
    <div class="table-wrap">
        <table class="standings-table">
            <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Team</th>
                <th scope="col">P</th>
                <th scope="col">W</th>
                <th scope="col">D</th>
                <th scope="col">L</th>
                <th scope="col">GF</th>
                <th scope="col">GA</th>
                <th scope="col">GD</th>
                <th scope="col">Pts</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td class="table-empty" colspan="10">No standings available yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) ($row['position'] ?? 0) ?></td>
                        <th scope="row">
                            <?= $escape($row['team_name'] ?? '') ?>
                            <?php if (!empty($row['tie_unresolved'])): ?>
                                <span class="tie-warning" title="Tie not resolved by the tie-break rules; order by registration" aria-label="Tie not resolved by the tie-break rules; order by registration">≈</span>
                            <?php endif; ?>
                        </th>
                        <td><?= (int) ($row['played'] ?? 0) ?></td>
                        <td><?= (int) ($row['wins'] ?? 0) ?></td>
                        <td><?= (int) ($row['draws'] ?? 0) ?></td>
                        <td><?= (int) ($row['losses'] ?? 0) ?></td>
                        <td><?= (int) ($row['goals_for'] ?? 0) ?></td>
                        <td><?= (int) ($row['goals_against'] ?? 0) ?></td>
                        <td><?= (int) ($row['goal_difference'] ?? 0) ?></td>
                        <td><strong><?= (int) ($row['points'] ?? 0) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
};
$formatStandings = array_values(array_filter(
    $standings,
    static fn (array $row): bool => $format === 'groups_knockout'
        ? ($row['group_id'] ?? null) !== null
        : ($row['group_id'] ?? null) === null
));
$fixtureGroups = [];
$knockoutRoundCount = 0;
foreach ($fixtures as $fixture) {
    if (!is_array($fixture)) {
        continue;
    }
    $stage = (string) ($fixture['stage'] ?? 'other');
    $round = (int) ($fixture['round_number'] ?? 1);
    if ($stage === 'knockout') {
        $knockoutRoundCount = max($knockoutRoundCount, $round);
    }
    $groupName = (string) ($fixture['group_name'] ?? '');
    $key = $stage . ':' . $groupName . ':' . $round;
    $fixtureGroups[$key] ??= [
        'stage' => $stage,
        'round' => $round,
        'group_name' => $groupName,
        'matches' => [],
    ];
    $fixtureGroups[$key]['matches'][] = $fixture;
}
$knockoutRoundTitle = static function (int $round) use ($knockoutRoundCount): string {
    return match ($knockoutRoundCount - $round + 1) {
        1 => 'Final',
        2 => 'Semi-finals',
        3 => 'Quarter-finals',
        4 => 'Round of 16',
        5 => 'Round of 32',
        default => 'Round ' . $round,
    };
};
?>
<section class="page-hero">
    <div class="container tournament-heading">
        <div>
            <a class="back-link" href="/">&larr; All tournaments</a>
            <p class="eyebrow">Tournament overview</p>
            <h1><?= $escape($tournament['name'] ?? 'Tournament') ?></h1>
            <div class="meta-row">
                <span class="badge badge-<?= $escape($format) ?>"><?= $escape($formatLabels[$format] ?? 'Tournament') ?></span>
                <span><?= count($teams) ?> <?= count($teams) === 1 ? 'team' : 'teams' ?></span>
                <?php if (isset($tournament['created_at'])): ?>
                    <span>Created <?= $escape(date('M j, Y', strtotime((string) $tournament['created_at']))) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <a class="button button-dark" href="/tournament/login">Admin login</a>
    </div>
</section>

<section class="section container">
    <?php if ($format === 'league' || $format === 'groups_knockout'): ?>
        <div class="section-heading">
            <div>
                <p class="eyebrow">The table</p>
                <h2><?= $format === 'groups_knockout' ? 'Group standings' : 'League standings' ?></h2>
            </div>
        </div>

        <?php if ($format === 'groups_knockout'): ?>
            <?php foreach ($groups as $group): ?>
                <?php
                if (!is_array($group)) {
                    continue;
                }
                $groupId = (int) ($group['id'] ?? 0);
                $groupStandings = array_values(array_filter(
                    $formatStandings,
                    static fn (array $row): bool => (int) ($row['group_id'] ?? 0) === $groupId
                ));
                ?>
                <section class="subsection" aria-labelledby="group-title-<?= $groupId ?>">
                    <div class="subsection-heading">
                        <h3 id="group-title-<?= $groupId ?>">Group <?= $escape($group['name'] ?? '') ?></h3>
                        <span class="muted"><?= count($groupStandings) ?> teams</span>
                    </div>
                    <?= $renderStandingsTable($groupStandings) ?>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <?= $renderStandingsTable($formatStandings) ?>
        <?php endif; ?>
    <?php endif; ?>

    <div class="section-heading fixtures-heading">
        <div>
            <p class="eyebrow">Match day</p>
            <h2>Fixtures &amp; results</h2>
        </div>
    </div>
    <?php if ($fixtureGroups === []): ?>
        <div class="empty-state compact-empty">
            <h3>No fixtures yet</h3>
            <p>The schedule will appear here once fixtures have been generated.</p>
        </div>
    <?php else: ?>
        <?php foreach ($fixtureGroups as $group): ?>
            <?php
            $stage = (string) $group['stage'];
            $round = (int) $group['round'];
            $roundTitle = $stage === 'knockout' ? $knockoutRoundTitle($round) : 'Matchday ' . $round;
            ?>
            <section class="fixture-round">
                <div class="subsection-heading">
                    <div>
                        <h3><?= $escape($group['group_name'] !== '' ? 'Group ' . $group['group_name'] . ' · ' . $roundTitle : $roundTitle) ?></h3>
                        <span class="muted"><?= $escape($stageLabels[$stage] ?? 'Fixtures') ?></span>
                    </div>
                </div>
                <div class="fixture-list">
                    <?php foreach ($group['matches'] as $fixture): ?>
                        <?php
                        $homeScore = $fixture['home_score'] ?? null;
                        $awayScore = $fixture['away_score'] ?? null;
                        $played = $homeScore !== null && $awayScore !== null;
                        $homeTeamName = $fixture['home_team_name'] ?? null;
                        $awayTeamName = $fixture['away_team_name'] ?? null;
                        $awayDisplayName = $stage === 'knockout' && $round === 1 && $awayTeamName === null
                            ? 'BYE'
                            : ($awayTeamName ?? 'TBD');
                        ?>
                        <article class="fixture-card">
                            <span class="fixture-number">#<?= (int) ($fixture['match_number'] ?? 0) ?></span>
                            <span class="fixture-team <?= $played && (int) $homeScore < (int) $awayScore ? 'is-loser' : '' ?>"><?= $escape($homeTeamName ?? 'TBD') ?></span>
                            <span class="fixture-score">
                                <?php if ($played): ?>
                                    <?= (int) $homeScore ?> <span>–</span> <?= (int) $awayScore ?>
                                <?php else: ?>
                                    <span class="fixture-vs">vs</span>
                                <?php endif; ?>
                            </span>
                            <span class="fixture-team fixture-team-away <?= $played && (int) $awayScore < (int) $homeScore ? 'is-loser' : '' ?>"><?= $escape($awayDisplayName) ?></span>
                            <?php if (($fixture['home_penalties'] ?? null) !== null): ?>
                                <span class="penalty-result">Pens <?= (int) $fixture['home_penalties'] ?>–<?= (int) $fixture['away_penalties'] ?></span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
