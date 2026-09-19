<?php

use App\Support\Url;

/**
 * Two-step login on the Settings screen (PLAN.md D-050): whether it is on, and the ways to
 * change that. Its forms are its own, outside the settings form.
 *
 * @var array{on: bool, codesLeft: int} $twoStep
 * @var string $csrf
 */
?>
        <div class="panel stack" id="two-step">
            <h2><?= e(t('twofactor.title')) ?></h2>
            <p class="hint"><?= e(t('twofactor.intro')) ?></p>
<?php if (!$twoStep['on']): ?>
            <p><?= e(t('twofactor.off_now')) ?></p>
            <p><a class="button button-secondary" href="<?= e(Url::admin('two-step')) ?>"><?= e(t('twofactor.set_up')) ?></a></p>
<?php else: ?>
            <p><strong><?= e(t('twofactor.on_now')) ?></strong> <?= e(t('twofactor.codes_left', ['left' => (string) $twoStep['codesLeft']])) ?></p>
            <form method="post" action="<?= e(Url::admin('two-step', 'codes')) ?>" class="two-step-inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="two-step-renew-code"><?= e(t('twofactor.renew_label')) ?></label>
                <input type="text" id="two-step-renew-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required spellcheck="false">
                <button type="submit" class="button button-secondary"><?= e(t('twofactor.renew')) ?></button>
            </form>
            <form method="post" action="<?= e(Url::admin('two-step', 'off')) ?>" class="two-step-inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="two-step-off-password"><?= e(t('twofactor.off_label')) ?></label>
                <input type="password" id="two-step-off-password" name="password" autocomplete="current-password" required>
                <button type="submit" class="button button-ghost button-danger"><?= e(t('twofactor.turn_off')) ?></button>
            </form>
            <p class="hint"><?= e(t('twofactor.lost_phone', ['file' => 'storage/' . \App\Modules\Auth\TwoFactor::RESET_FILE])) ?></p>
<?php endif; ?>
        </div>
