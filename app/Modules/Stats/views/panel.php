<?php

use App\Modules\Stats\Tracker;
use App\Support\Url;

/**
 * The Statistics panel on the Settings screen (PLAN.md D-051): on or off, whether a
 * browser's Do Not Track or Global Privacy Control is honoured, how long counts are kept,
 * and a way to delete them all. Its forms are its own, outside the settings form.
 *
 * @var array{enabled: bool, dnt: bool, retention: int} $stats
 * @var string $csrf
 */
?>
        <div class="panel stack" id="statistics">
            <h2><?= e(t('stats.title')) ?></h2>
            <p class="hint"><?= e(t('stats.intro')) ?></p>
            <p><?= e(t($stats['enabled'] ? 'stats.on_now' : 'stats.off_now')) ?></p>

            <form method="post" action="<?= e(Url::admin('settings', 'statistics')) ?>" class="stack">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label class="checkbox"><input type="checkbox" name="stats_enabled" value="1"<?= $stats['enabled'] ? ' checked' : '' ?>> <span><?= e(t('stats.enabled')) ?></span></label>
                <label class="checkbox"><input type="checkbox" name="stats_dnt" value="1"<?= $stats['dnt'] ? ' checked' : '' ?>> <span><?= e(t('stats.dnt')) ?></span></label>
                <div class="field">
                    <label for="stats_retention"><?= e(t('stats.retention')) ?></label>
                    <select id="stats_retention" name="stats_retention" aria-describedby="stats_retention-hint">
<?php foreach (Tracker::RETENTION as $months): ?>
                        <option value="<?= e($months) ?>"<?= $months === $stats['retention'] ? ' selected' : '' ?>><?= e(t('stats.months', ['months' => (string) $months])) ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="hint" id="stats_retention-hint"><?= e(t('stats.retention_hint')) ?></span>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-secondary"><?= e(t('stats.save')) ?></button>
                </div>
            </form>

            <form method="post" action="<?= e(Url::admin('settings', 'statistics', 'erase')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="button button-ghost button-danger" data-confirm="<?= e(t('stats.erase_confirm')) ?>"><?= e(t('stats.erase')) ?></button>
            </form>
        </div>
