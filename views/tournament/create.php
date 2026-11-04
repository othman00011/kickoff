<?php
declare(strict_types=1);

$csrfTokenField = isset($csrfTokenField) && is_string($csrfTokenField) ? $csrfTokenField : '';
?>
<section class="page-hero page-hero-compact">
    <div class="container">
        <p class="eyebrow">Set the teams in motion</p>
        <h1>Create a tournament</h1>
        <p>Choose your format, add your teams, and we’ll prepare the fixtures.</p>
    </div>
</section>
<section class="section container narrow-container">
    <form class="form-card create-form" action="/tournament/create" method="post" data-multi-step novalidate>
        <?= $csrfTokenField ?>
        <div class="step-progress" aria-label="Form progress">
            <span class="step-indicator is-current" data-step-indicator="0"><b>1</b><span>Details</span></span>
            <span class="step-connector"></span>
            <span class="step-indicator" data-step-indicator="1"><b>2</b><span>Teams</span></span>
            <span class="step-connector"></span>
            <span class="step-indicator" data-step-indicator="2"><b>3</b><span>Rules</span></span>
            <span class="step-connector"></span>
            <span class="step-indicator" data-step-indicator="3"><b>4</b><span>Review</span></span>
        </div>

        <section class="form-step" data-form-step="0" aria-labelledby="step-one-title">
            <p class="eyebrow">Step 1 of 4</p>
            <h2 id="step-one-title">Name your tournament</h2>
            <div class="field">
                <label for="tournament-name">Tournament name <span class="required-mark">*</span></label>
                <input id="tournament-name" type="text" name="name" maxlength="100" required autocomplete="off" placeholder="e.g. Riverside Summer Cup">
            </div>
            <fieldset class="field">
                <legend>Competition format <span class="required-mark">*</span></legend>
                <div class="choice-grid">
                    <label class="choice-card">
                        <input type="radio" name="format" value="league" required checked>
                        <span class="choice-icon" aria-hidden="true">&#9776;</span>
                        <strong>League</strong>
                        <small>Everyone plays everyone.</small>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="format" value="knockout" required>
                        <span class="choice-icon" aria-hidden="true">&#10140;</span>
                        <strong>Knockout</strong>
                        <small>Win to stay in the bracket.</small>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="format" value="groups_knockout" required>
                        <span class="choice-icon" aria-hidden="true">&#9638;</span>
                        <strong>Groups + knockout</strong>
                        <small>Group stage, then finals.</small>
                    </label>
                </div>
            </fieldset>
        </section>

        <section class="form-step" data-form-step="1" aria-labelledby="step-two-title">
            <p class="eyebrow">Step 2 of 4</p>
            <h2 id="step-two-title">Add the teams</h2>
            <div class="field">
                <label for="team-list">Team names <span class="required-mark">*</span></label>
                <textarea id="team-list" name="teams" rows="9" maxlength="20480" required placeholder="One team per line&#10;Northside FC&#10;Riverside United&#10;City Athletic"></textarea>
                <span class="field-hint">Enter 2–64 teams, one per line. Each name can contain up to 100 characters.</span>
            </div>
        </section>

        <section class="form-step" data-form-step="2" aria-labelledby="step-three-title">
            <p class="eyebrow">Step 3 of 4</p>
            <h2 id="step-three-title">Group settings</h2>
            <div class="group-settings-panel" data-group-settings hidden>
                <p class="muted">These settings apply when you choose “Groups + knockout”. Teams are distributed across the groups in the order entered.</p>
                <div class="field-row">
                    <div class="field">
                        <label for="groups-count">Number of groups</label>
                        <input id="groups-count" type="number" name="groups_count" min="2" max="16" placeholder="2" inputmode="numeric">
                    </div>
                    <div class="field">
                        <label for="qualifiers-per-group">Qualifiers per group</label>
                        <input id="qualifiers-per-group" type="number" name="qualifiers_per_group" min="1" max="32" placeholder="2" inputmode="numeric">
                    </div>
                </div>
                <p class="field-hint">Groups are named A, B, C, and so on. The organizer generates the knockout bracket once all group matches are played.</p>
            </div>
            <div class="group-settings-placeholder" data-group-placeholder>
                <div class="info-panel">
                    <strong>No group settings needed.</strong>
                    <span>Choose “Groups + knockout” in step 1 to configure group play.</span>
                </div>
            </div>
        </section>

        <section class="form-step" data-form-step="3" aria-labelledby="step-four-title">
            <p class="eyebrow">Step 4 of 4</p>
            <h2 id="step-four-title">Rules and admin access</h2>
            <p class="muted">Points are used for league and group standings.</p>
            <div class="field-row field-row-three">
                <div class="field">
                    <label for="points-win">Win points</label>
                    <input id="points-win" type="number" name="points_win" min="0" max="32767" value="3" required>
                </div>
                <div class="field">
                    <label for="points-draw">Draw points</label>
                    <input id="points-draw" type="number" name="points_draw" min="0" max="32767" value="1" required>
                </div>
                <div class="field">
                    <label for="points-loss">Loss points</label>
                    <input id="points-loss" type="number" name="points_loss" min="0" max="32767" value="0" required>
                </div>
            </div>
            <div class="field" data-round-robin-setting>
                <label for="round-robin-legs">Games between each pair</label>
                <select id="round-robin-legs" name="round_robin_legs">
                    <option value="1" selected>One leg</option>
                    <option value="2">Two legs (home and away)</option>
                </select>
                <span class="field-hint">Applies to league fixtures or the group stage.</span>
            </div>
            <div class="field">
                <label for="admin-password">Tournament admin password <span class="required-mark">*</span></label>
                <input id="admin-password" type="password" name="admin_password" required autocomplete="new-password" minlength="8" maxlength="72">
                <span class="field-hint">Use 8–72 bytes. Keep this password safe; it is required to manage this tournament.</span>
            </div>
            <div class="info-panel">
                <strong>Ready to kick off?</strong>
                <span>Creating the tournament will generate its initial fixtures. Tournament settings cannot be changed afterwards.</span>
            </div>
        </section>

        <div class="form-navigation">
            <button class="button button-secondary" type="button" data-step-back hidden>&larr; Back</button>
            <span class="form-navigation-spacer"></span>
            <button class="button button-primary" type="button" data-step-next>Continue <span aria-hidden="true">&rarr;</span></button>
            <button class="button button-primary" type="submit" data-step-submit hidden>Create tournament <span aria-hidden="true">&rarr;</span></button>
        </div>
    </form>
</section>
