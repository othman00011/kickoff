<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Kickoff | Football tournaments';
$flashMessage = isset($flashMessage) && is_array($flashMessage) ? $flashMessage : null;
$flashType = $flashMessage['type'] ?? '';
$flashText = $flashMessage['message'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123c35">
    <meta name="description" content="Create and follow local football tournaments, fixtures and standings.">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/" aria-label="Kickoff home">
            <span class="brand-mark" aria-hidden="true">K</span>
            <span>Kickoff<span class="brand-period">.</span></span>
        </a>
        <nav class="site-nav" aria-label="Main navigation">
            <a href="/">Home</a>
            <a href="/tournament/create">Create tournament</a>
            <a href="/tournament/login">Admin login</a>
        </nav>
    </div>
</header>
<?php if ($flashMessage !== null && is_string($flashType) && is_string($flashText)): ?>
    <?php if (in_array($flashType, ['success', 'error', 'info'], true)): ?>
        <div class="container flash-container">
            <div class="alert alert-<?= $escape($flashType) ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>" data-flash>
                <span><?= $escape($flashText) ?></span>
                <button class="alert-dismiss" type="button" aria-label="Dismiss notification">&times;</button>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<main id="main-content">
