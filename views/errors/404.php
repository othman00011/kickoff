<?php
declare(strict_types=1);

$errorMessage = isset($errorMessage) && is_string($errorMessage) ? $errorMessage : '';
?>
<section class="section container error-page">
    <p class="error-code">404</p>
    <p class="eyebrow">Offside</p>
    <h1>We can’t find that page.</h1>
    <p><?= $escape($errorMessage !== '' ? $errorMessage : 'The link may be out of date, or the page may have moved. Let’s get you back to the pitch.') ?></p>
    <a class="button button-primary" href="/">Back to home <span aria-hidden="true">&rarr;</span></a>
</section>
