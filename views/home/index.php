<?php
declare(strict_types=1);

$tournaments = isset($tournaments) && is_array($tournaments) ? $tournaments : [];
$query = isset($query) && is_string($query) ? $query : '';
$page = isset($page) && is_int($page) ? $page : 1;
$totalPages = isset($totalPages) && is_int($totalPages) ? $totalPages : 1;
$total = isset($total) && is_int($total) ? $total : count($tournaments);
$formatLabels = [
    'league' => 'League',
    'knockout' => 'Knockout',
    'groups_knockout' => 'Groups + knockout',
];
$paginationUrl = static function (int $targetPage) use ($query): string {
    $parameters = ['page' => $targetPage];
    if ($query !== '') {
        $parameters['q'] = $query;
    }

    return '/?' . http_build_query($parameters);
};
?>
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy">
            <p class="eyebrow">Your tournament starts here</p>
            <h1>Bring your teams.<br><span>Make it a competition.</span></h1>
            <p class="hero-description">Set up a local football tournament, keep every fixture in one place, and follow the standings as the games unfold.</p>
            <div class="button-row">
                <a class="button button-primary" href="/tournament/create">Create a tournament <span aria-hidden="true">&rarr;</span></a>
                <a class="button button-secondary" href="/tournament/login">Manage a tournament</a>
            </div>
            <div class="hero-note"><span class="status-dot"></span> No player accounts required</div>
        </div>
        <div class="hero-art" aria-hidden="true">
            <div class="pitch">
                <span class="pitch-line pitch-mid"></span>
                <span class="pitch-center"></span>
                <span class="pitch-box pitch-box-left"></span>
                <span class="pitch-box pitch-box-right"></span>
                <span class="pitch-ball">&#9917;</span>
            </div>
            <div class="hero-sticker"><strong>90'</strong><span>Make it count</span></div>
        </div>
    </div>
</section>

<section class="section container" aria-labelledby="tournaments-heading">
    <div class="section-heading">
        <div>
            <p class="eyebrow">The latest action</p>
            <h2 id="tournaments-heading">Find your tournament</h2>
        </div>
        <span class="muted"><?= $total ?> <?= $total === 1 ? 'tournament' : 'tournaments' ?></span>
    </div>

    <form class="search-form" method="get" action="/" role="search">
        <label class="sr-only" for="tournament-search">Search tournaments by name</label>
        <span class="search-icon" aria-hidden="true">&#9906;</span>
        <input id="tournament-search" type="search" name="q" value="<?= $escape($query) ?>" placeholder="Search tournaments by name..." maxlength="200">
        <button class="button button-dark" type="submit">Search</button>
        <?php if ($query !== ''): ?>
            <a class="button button-quiet" href="/">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($tournaments === []): ?>
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">&#9917;</div>
            <h3><?= $query !== '' ? 'No matches found' : 'The pitch is open' ?></h3>
            <p><?= $query !== '' ? 'Try another tournament name, or clear your search.' : 'There are no tournaments yet. Create one and invite your teams.' ?></p>
            <?php if ($query === ''): ?>
                <a class="button button-primary" href="/tournament/create">Create the first tournament</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tournament-grid">
            <?php foreach ($tournaments as $tournament): ?>
                <?php
                if (!is_array($tournament)) {
                    continue;
                }
                $tournamentId = (int) ($tournament['id'] ?? 0);
                $tournamentName = (string) ($tournament['name'] ?? 'Untitled tournament');
                $format = (string) ($tournament['format'] ?? '');
                $createdAt = $tournament['created_at'] ?? null;
                ?>
                <article class="tournament-card">
                    <div class="card-topline">
                        <span class="badge badge-<?= $escape($format) ?>"><?= $escape($formatLabels[$format] ?? 'Tournament') ?></span>
                        <span class="card-ball" aria-hidden="true">&#9917;</span>
                    </div>
                    <h3><a href="/tournament/show/<?= $tournamentId ?>"><?= $escape($tournamentName) ?></a></h3>
                    <p class="card-date">
                        <?php if (is_string($createdAt) && $createdAt !== ''): ?>
                            Created <?= $escape(date('M j, Y', strtotime($createdAt))) ?>
                        <?php else: ?>
                            Ready to play
                        <?php endif; ?>
                    </p>
                    <a class="card-link" href="/tournament/show/<?= $tournamentId ?>">View tournament <span aria-hidden="true">&rarr;</span></a>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Tournament pages">
                <?php if ($page > 1): ?>
                    <a class="button button-secondary" href="<?= $escape($paginationUrl($page - 1)) ?>" rel="prev">&larr; Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <span class="pagination-status">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="button button-dark" href="<?= $escape($paginationUrl($page + 1)) ?>" rel="next">View more <span aria-hidden="true">&rarr;</span></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
