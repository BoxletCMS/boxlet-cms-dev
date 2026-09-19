<?php

use App\Modules\Stats\Tracker;
use App\Support\Url;

/**
 * The Statistics panel on the Settings screen (PLAN.md D-051): on or off, whether a
 * browser's Do Not Track or Global Privacy Control is honoured, how long counts are kept,
 * and a way to delete them all. Its forms are its own, outside the settings form.
 *
 * @var array{enabled: bool, dnt: bool, retention: int} $stats
 * @var array{built: string, type: string}|null $geo the country database in use
 * @var string $uploadLimit the largest file this server accepts, as php.ini says it
 * @var array<string, array{language: string, text: string}> $privacy the suggested policy text, by language
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

            <?php /* The country database (D-051): optional, fetched only when asked. */ ?>
            <fieldset class="fieldset stack">
                <legend><?= e(t('stats.geo_title')) ?></legend>
                <p class="hint"><?= e(t('stats.geo_intro')) ?></p>
                <p><?= e($geo === null ? t('stats.geo_none') : t('stats.geo_in_use', ['date' => $geo['built']])) ?></p>
                <form method="post" action="<?= e(Url::admin('settings', 'statistics', 'countries')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="button button-secondary"><?= e(t($geo === null ? 'stats.geo_download' : 'stats.geo_update')) ?></button>
                </form>
                <form method="post" action="<?= e(Url::admin('settings', 'statistics', 'countries', 'upload')) ?>" enctype="multipart/form-data" class="stack">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="field">
                        <label for="geo_file"><?= e(t('stats.geo_upload')) ?></label>
                        <input type="file" id="geo_file" name="geo_file" accept=".mmdb,.gz" aria-describedby="geo_file-hint">
                        <span class="hint" id="geo_file-hint"><?= e(t('stats.geo_upload_hint', ['limit' => $uploadLimit])) ?></span>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button button-secondary"><?= e(t('stats.geo_upload_button')) ?></button>
                    </div>
                </form>
            </fieldset>

            <?php /* What to tell visitors (D-051): the text written for these settings, in
                     each language there is one for, in a field that selects and copies easily. */ ?>
            <fieldset class="fieldset stack">
                <legend><?= e(t('stats.privacy_title')) ?></legend>
                <p class="hint"><?= e(t('stats.privacy_intro')) ?></p>
<?php foreach ($privacy as $code => $version): ?>
                <details class="stats-privacy">
                    <summary><?= e($version['language']) ?></summary>
                    <div class="field">
                        <label for="stats-privacy-<?= e($code) ?>" class="visually-hidden"><?= e(t('stats.privacy_title') . ' — ' . $version['language']) ?></label>
                        <textarea id="stats-privacy-<?= e($code) ?>" rows="14" readonly lang="<?= e($code) ?>"><?= e($version['text']) ?></textarea>
                    </div>
                </details>
<?php endforeach; ?>
            </fieldset>

            <form method="post" action="<?= e(Url::admin('settings', 'statistics', 'erase')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="button button-ghost button-danger" data-confirm="<?= e(t('stats.erase_confirm')) ?>"><?= e(t('stats.erase')) ?></button>
            </form>
        </div>
