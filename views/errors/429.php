<?php
declare(strict_types=1);

$retryMinutes = isset($retryMinutes) && is_int($retryMinutes) ? max(1, $retryMinutes) : 1;
?>
<section class="section container error-page">
    <p class="error-code">429</p>
    <p class="eyebrow">Take a short break</p>
    <h1>Too many failed attempts.</h1>
    <p>Too many failed attempts. Try again in <?= $retryMinutes ?> <?= $retryMinutes === 1 ? 'minute' : 'minutes' ?>.</p>
    <a class="button button-primary" href="/tournament/login">Return to admin login</a>
</section>
