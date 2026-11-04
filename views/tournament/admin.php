<?php
declare(strict_types=1);

$tournament = isset($tournament) && is_array($tournament) ? $tournament : [];
$groups = isset($groups) && is_array($groups) ? $groups : [];
$teams = isset($teams) && is_array($teams) ? $teams : [];
$standings = isset($standings) && is_array($standings) ? $standings : [];
$fixtures = isset($fixtures) && is_array($fixtures) ? $fixtures : [];
$csrfTokenField = isset($csrfTokenField) && is_string($csrfTokenField) ? $csrfTokenField : '';
$remainingGroupMatches = isset($remainingGroupMatches) ? max(0, (int) $remainingGroupMatches) : 0;
$knockoutGenerated = isset($knockoutGenerated) && is_bool($knockoutGenerated) ? $knockoutGenerated : false;
$knockoutHasScores = isset($knockoutHasScores) && is_bool($knockoutHasScores) ? $knockoutHasScores : false;
$qualifierPreview = isset($qualifierPreview) && is_array($qualifierPreview) ? $qualifierPreview : [];
$tournamentId = (int) ($tournament['id'] ?? 0);
$format = (string) ($tournament['format'] ?? '');
$formatLabels = ['league' => 'League', 'knockout' => 'Knockout', 'groups_knockout' => 'Groups + knockout'];
$roundGroups = [];
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
    $roundGroups[$key] ??= [
        'stage' => $stage,
        'round' => $round,
        'group_name' => $groupName,
        'matches' => [],
    ];
    $roundGroups[$key]['matches'][] = $fixture;
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
$groupCount = count($groups);
$standingsByGroup = [];
foreach ($standings as $standing) {
    $groupId = $standing['group_id'] ?? null;
    $standingsByGroup[$groupId === null ? 'league' : (string) $groupId][] = $standing;
}
$renderStandingsTable = static function (array $rows) use ($escape): string {
    ob_start();
    ?>
    <div class="table-wrap">
        <table class="standings-table">
            <thead><tr><th scope="col">#</th><th scope="col">Team</th><th scope="col">P</th><th scope="col">W</th><th scope="col">D</th><th scope="col">L</th><th scope="col">GD</th><th scope="col">Pts</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td class="table-empty" colspan="8">No standings available yet.</td></tr>
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
?>
<section class="page-hero page-hero-compact">
    <div class="container tournament-heading">
        <div>
            <p class="eyebrow">Tournament control room</p>
            <h1><?= $escape($tournament['name'] ?? 'Tournament') ?></h1>
            <div class="meta-row">
                <span class="badge badge-<?= $escape($format) ?>"><?= $escape($formatLabels[$format] ?? 'Tournament') ?></span>
                <span><?= count($teams) ?> <?= count($teams) === 1 ? 'team' : 'teams' ?></span>
                <?php if ($groupCount > 0): ?><span><?= $groupCount ?> <?= $groupCount === 1 ? 'group' : 'groups' ?></span><?php endif; ?>
            </div>
        </div>
        <a class="button button-secondary" href="/tournament/show/<?= $tournamentId ?>">Public view</a>
        <form action="/tournament/logout" method="post">
            <?= $csrfTokenField ?>
            <button class="button button-secondary" type="submit">Log out</button>
        </form>
    </div>
</section>

<section class="section container">
    <div class="section-heading">
        <div><p class="eyebrow">Keep the table current</p><h2>Standings</h2></div>
    </div>
    <?php if ($groups !== []): ?>
        <div class="standings-groups">
            <?php foreach ($groups as $group): ?>
                <?php $groupId = (string) ($group['id'] ?? ''); ?>
                <section class="subsection">
                    <div class="subsection-heading"><h3>Group <?= $escape($group['name'] ?? '') ?></h3></div>
                    <?= $renderStandingsTable($standingsByGroup[$groupId] ?? []) ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php elseif ($format === 'league'): ?>
        <?= $renderStandingsTable($standingsByGroup['league'] ?? []) ?>
    <?php else: ?>
        <p class="muted">Standings are available for league and group-stage matches.</p>
    <?php endif; ?>
</section>

<?php if ($format === 'groups_knockout'): ?>
    <section class="section container">
        <div class="bracket-control-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Next stage</p>
                    <h2>Knockout stage</h2>
                </div>
            </div>
            <?php if (!$knockoutGenerated && $remainingGroupMatches > 0): ?>
                <p class="muted"><?= (int) $remainingGroupMatches ?> group <?= $remainingGroupMatches === 1 ? 'match still needs' : 'matches still need' ?> a result.</p>
                <form action="/tournament/generateKnockout" method="post">
                    <?= $csrfTokenField ?>
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <button class="button button-primary" type="submit" disabled>Generate knockout bracket</button>
                </form>
            <?php elseif (!$knockoutGenerated): ?>
                <p class="muted">Review the qualifiers below. Seeds follow group rank, then points, goal difference, goals scored, and group order.</p>
                <div class="qualifier-preview">
                    <?php foreach ($qualifierPreview as $previewGroup): ?>
                        <section>
                            <h3>Group <?= $escape($previewGroup['name'] ?? '') ?></h3>
                            <ol>
                                <?php foreach ($previewGroup['qualifiers'] ?? [] as $qualifier): ?>
                                    <li>
                                        <span>Seed <?= (int) ($qualifier['seed'] ?? 0) ?></span>
                                        <strong><?= $escape($qualifier['team_name'] ?? '') ?></strong>
                                        <small>Rank <?= (int) ($qualifier['group_rank'] ?? 0) ?> in group</small>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </section>
                    <?php endforeach; ?>
                </div>
                <form action="/tournament/generateKnockout" method="post" data-confirm="Generate the knockout bracket from these group results?">
                    <?= $csrfTokenField ?>
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <button class="button button-primary" type="submit">Generate knockout bracket</button>
                </form>
            <?php elseif (!$knockoutHasScores): ?>
                <p class="muted">The bracket is ready. Group-stage results are locked until the knockout stage is reset.</p>
                <form action="/tournament/resetKnockout" method="post" data-confirm="Reset the knockout stage? This removes the unplayed bracket and unlocks group results.">
                    <?= $csrfTokenField ?>
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <button class="button button-danger" type="submit">Reset knockout stage</button>
                </form>
            <?php else: ?>
                <p class="info-panel">Knockout stage in progress. Group-stage results are locked.</p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="section section-muted">
    <div class="container">
        <div class="section-heading">
            <div><p class="eyebrow">Update as matches finish</p><h2>Fixtures &amp; results</h2></div>
        </div>
        <?php if ($roundGroups === []): ?>
            <div class="empty-state compact-empty"><h3>No fixtures found</h3><p>This tournament has no scheduled matches.</p></div>
        <?php else: ?>
            <?php foreach ($roundGroups as $roundGroup): ?>
                <?php
                $stage = (string) $roundGroup['stage'];
                $round = (int) $roundGroup['round'];
                $roundTitle = $stage === 'knockout' ? $knockoutRoundTitle($round) : 'Matchday ' . $round;
                $heading = $roundGroup['group_name'] !== ''
                    ? 'Group ' . $roundGroup['group_name'] . ' · ' . $roundTitle
                    : $roundTitle;
                ?>
                <section class="fixture-round admin-round">
                    <div class="subsection-heading">
                        <h3><?= $escape($heading) ?></h3>
                        <span class="muted"><?= $escape(ucfirst($stage)) ?> stage</span>
                    </div>
                    <div class="admin-match-list">
                        <?php foreach ($roundGroup['matches'] as $fixture): ?>
                            <?php
                            $matchId = (int) ($fixture['id'] ?? 0);
                            $homeTeamName = $fixture['home_team_name'] ?? null;
                            $awayTeamName = $fixture['away_team_name'] ?? null;
                            $hasBothTeams = $homeTeamName !== null && $awayTeamName !== null;
                            ?>
                            <article class="admin-match-card">
                                <div class="admin-match-title">
                                    <span class="fixture-number">Match #<?= (int) ($fixture['match_number'] ?? 0) ?></span>
                                    <span class="match-status <?= ($fixture['home_score'] ?? null) !== null ? 'match-status-played' : '' ?>">
                                        <?= ($fixture['home_score'] ?? null) !== null ? 'Result saved' : ($hasBothTeams ? 'Awaiting result' : 'Waiting for teams') ?>
                                    </span>
                                </div>
                                <?php if ($hasBothTeams): ?>
                                    <form class="score-form" action="/match/updateScore" method="post">
                                        <?= $csrfTokenField ?>
                                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                        <input type="hidden" name="match_id" value="<?= $matchId ?>">
                                        <div class="score-team">
                                            <label for="home-score-<?= $matchId ?>"><?= $escape($homeTeamName) ?></label>
                                            <input id="home-score-<?= $matchId ?>" type="number" name="home_score" min="0" max="32767" value="<?= $escape($fixture['home_score'] ?? '') ?>" inputmode="numeric" aria-label="Home score for match <?= $matchId ?>">
                                        </div>
                                        <span class="score-separator" aria-hidden="true">:</span>
                                        <div class="score-team score-team-away">
                                            <label for="away-score-<?= $matchId ?>"><?= $escape($awayTeamName) ?></label>
                                            <input id="away-score-<?= $matchId ?>" type="number" name="away_score" min="0" max="32767" value="<?= $escape($fixture['away_score'] ?? '') ?>" inputmode="numeric" aria-label="Away score for match <?= $matchId ?>">
                                        </div>
                                        <?php if ($stage === 'knockout'): ?>
                                            <div class="penalty-fields">
                                                <span>Penalties <small>(required if level)</small></span>
                                                <label class="sr-only" for="home-pens-<?= $matchId ?>">Home penalties</label>
                                                <input id="home-pens-<?= $matchId ?>" type="number" name="home_penalties" min="0" max="32767" value="<?= $escape($fixture['home_penalties'] ?? '') ?>" inputmode="numeric" placeholder="Home">
                                                <span aria-hidden="true">:</span>
                                                <label class="sr-only" for="away-pens-<?= $matchId ?>">Away penalties</label>
                                                <input id="away-pens-<?= $matchId ?>" type="number" name="away_penalties" min="0" max="32767" value="<?= $escape($fixture['away_penalties'] ?? '') ?>" inputmode="numeric" placeholder="Away">
                                            </div>
                                        <?php endif; ?>
                                        <button class="button button-small button-dark" type="submit">Save result</button>
                                    </form>
                                <?php elseif ($stage === 'knockout' && (($fixture['home_team_id'] ?? null) !== null || ($fixture['away_team_id'] ?? null) !== null)): ?>
                                    <p class="bye-note">A bye has advanced the listed team to the next round.</p>
                                <?php else: ?>
                                    <div class="fixture-card">
                                        <span class="fixture-team"><?= $escape($homeTeamName ?? 'TBD') ?></span>
                                        <span class="fixture-vs">vs</span>
                                        <span class="fixture-team fixture-team-away"><?= $escape($awayTeamName ?? 'TBD') ?></span>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="section container">
    <div class="danger-zone">
        <div>
            <p class="eyebrow">Irreversible action</p>
            <h2>Delete this tournament</h2>
            <p>Deleting the tournament permanently removes its teams, groups, fixtures, and results.</p>
        </div>
        <button class="button button-danger" type="button" data-open-delete>Delete tournament</button>
    </div>
</section>

<dialog class="confirm-dialog" data-delete-dialog aria-labelledby="delete-dialog-title">
    <form action="/tournament/delete" method="post">
        <?= $csrfTokenField ?>
        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
        <button class="dialog-close" type="button" data-close-delete aria-label="Close dialog">&times;</button>
        <p class="eyebrow">Please confirm</p>
        <h2 id="delete-dialog-title">Delete <?= $escape($tournament['name'] ?? 'this tournament') ?>?</h2>
        <p>This action cannot be undone. Enter the tournament admin password to continue.</p>
        <div class="field">
            <label for="delete-admin-password">Admin password</label>
            <input id="delete-admin-password" type="password" name="admin_password" required autocomplete="current-password">
        </div>
        <div class="button-row dialog-actions">
            <button class="button button-secondary" type="button" data-close-delete>Keep tournament</button>
            <button class="button button-danger" type="submit">Delete permanently</button>
        </div>
    </form>
</dialog>
