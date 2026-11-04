<?php
declare(strict_types=1);

$csrfTokenField = isset($csrfTokenField) && is_string($csrfTokenField) ? $csrfTokenField : '';
?>
<section class="page-hero page-hero-compact">
    <div class="container">
        <p class="eyebrow">Organizer access</p>
        <h1>Manage your tournament</h1>
        <p>Sign in with the tournament name and its admin password.</p>
    </div>
</section>
<section class="section container narrow-container">
    <form class="form-card login-card" action="/tournament/login" method="post">
        <?= $csrfTokenField ?>
        <div class="login-icon" aria-hidden="true">&#9917;</div>
        <h2>Admin login</h2>
        <p class="muted">Each tournament has its own admin password.</p>
        <div class="field">
            <label for="login-name">Tournament name</label>
            <input id="login-name" type="text" name="name" maxlength="100" required autocomplete="organization">
        </div>
        <div class="field">
            <label for="login-password">Admin password</label>
            <input id="login-password" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="button button-primary button-full" type="submit">Sign in</button>
        <p class="form-footnote">Looking for a public tournament? <a href="/">Browse tournaments</a>.</p>
    </form>
</section>
