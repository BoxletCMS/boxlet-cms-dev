<?php

use App\Modules\Media\MediaReference;
use App\Support\Url;

/**
 * Site settings (PLAN.md D-028). Provided by AdminView::render().
 *
 * TWO FORMS, on purpose. The settings write to /admin/settings; the maintenance switch
 * posts to /admin/maintenance, which already exists and owns the flag file. One form
 * cannot do both: the switch changes a file whose whole point is working when the
 * database does not, and nesting forms is not a thing HTML allows anyway.
 *
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var list<array{id: int, name: string, thumb: string|null, whole: string|null}> $pictures
 * @var list<string> $timezones
 * @var bool $maintenanceOn
 * @var string $title
 * @var string $csrf
 */
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>'
    : '';

$text = static fn (string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';
$picked = static fn (string $key): int => is_int($values[$key] ?? null) ? $values[$key] : 0;

/** A picture chooser. Without JavaScript this select IS the control, as in the block editor. */
$picker = static function (string $key, int $chosen, bool $whole = false) use ($pictures): string {
    // A logo keeps its shape (D-038): its preview is the uncropped picture, fitted rather
    // than filled, where every other picker shows the square thumbnail.
    $html = '<select id="' . e($key) . '" name="' . e($key) . '" data-media-field'
        . ($whole ? ' data-picker-whole' : '')
        . MediaReference::pickerAttributes() . '>';
    $html .= '<option value="">' . e(t('pages.field.media_none')) . '</option>';
    foreach ($pictures as $picture) {
        $preview = $whole ? ($picture['whole'] ?? $picture['thumb']) : $picture['thumb'];
        $html .= '<option value="' . e($picture['id']) . '"'
            . ($preview === null ? '' : ' data-thumb="' . e($preview) . '"')
            . ($chosen === $picture['id'] ? ' selected' : '') . '>'
            . e($picture['name']) . '</option>';
    }

    return $html . '</select>';
};
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
        <p class="page-subtitle"><?= e(t('settings.intro')) ?></p>
<?php if ($notice !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($notice) ?></p>
<?php endif; ?>

        <form method="post" action="<?= e(Url::admin('settings')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="panel stack">
                <div class="field">
                    <label for="site_name"><?= e(t('settings.site_name')) ?></label>
                    <input type="text" id="site_name" name="site_name" maxlength="120"
                           value="<?= e($text('site_name')) ?>" aria-describedby="site_name-hint">
                    <span class="hint" id="site_name-hint"><?= e(t('settings.site_name_hint')) ?></span>
                </div>

                <div class="field">
                    <label for="timezone"><?= e(t('settings.timezone')) ?></label>
                    <select id="timezone" name="timezone" aria-describedby="timezone-hint">
<?php foreach ($timezones as $zone): ?>
                        <option value="<?= e($zone) ?>"<?= $text('timezone') === $zone ? ' selected' : '' ?>><?= e($zone) ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="hint" id="timezone-hint"><?= e(t('settings.timezone_hint')) ?></span>
                    <?= $error('timezone') ?>
                </div>
            </div>

            <div class="panel stack">
                <h2><?= e(t('settings.branding')) ?></h2>
                <p class="hint"><?= e(t('settings.branding_intro')) ?></p>

                <div class="field">
                    <label for="site_logo"><?= e(t('settings.logo')) ?></label>
                    <?= $picker('site_logo', $picked('site_logo'), true) ?>
                    <span class="hint"><?= e(t('settings.logo_hint')) ?></span>
                </div>

                <div class="field">
                    <label for="site_favicon"><?= e(t('settings.favicon')) ?></label>
                    <?= $picker('site_favicon', $picked('site_favicon')) ?>
                    <span class="hint"><?= e(t('settings.favicon_hint')) ?></span>
                </div>

                <div class="field">
                    <label for="site_share_image"><?= e(t('settings.share_image')) ?></label>
                    <?= $picker('site_share_image', $picked('site_share_image')) ?>
                    <span class="hint"><?= e(t('settings.share_image_hint')) ?></span>
                </div>
            </div>

            <button type="submit" class="button"><?= e(t('settings.save')) ?></button>
        </form>

        <?php /* MAINTENANCE IN ONE PLACE (D-038): the switch, and the message visitors see
                 while it is on. Two forms, because HTML has none nested and the switch posts
                 to /admin/maintenance, which owns the flag file (D-021); the message is a
                 setting and saves with its own button. The state is said in words first:
                 "Turn on maintenance mode" alone does not say which way round the site is. */ ?>
        <div class="panel stack">
            <h2><?= e(t('maintenance.title')) ?></h2>
            <p class="hint"><?= e(t('settings.maintenance_intro')) ?></p>
            <p class="<?= $maintenanceOn ? 'notice notice-warning' : 'hint' ?>"<?= $maintenanceOn ? ' role="status"' : '' ?>>
                <?= e($maintenanceOn ? t('maintenance.on_now') : t('maintenance.off_now')) ?>
            </p>
            <form method="post" action="<?= e(Url::admin('maintenance')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="state" value="<?= $maintenanceOn ? 'off' : 'on' ?>">
                <input type="hidden" name="return" value="settings">
                <button type="submit" class="button<?= $maintenanceOn ? '' : ' button-secondary' ?>">
                    <?= e($maintenanceOn ? t('maintenance.turn_off') : t('maintenance.turn_on')) ?>
                </button>
            </form>

            <form method="post" action="<?= e(Url::admin('settings', 'maintenance-message')) ?>" class="stack maintenance-message">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="field">
                    <label for="maintenance_message"><?= e(t('settings.maintenance_message')) ?></label>
                    <textarea id="maintenance_message" name="maintenance_message" rows="3"
                              placeholder="<?= e(t('maintenance.public.body')) ?>"
                              aria-describedby="maintenance_message-hint"><?= e($text('maintenance_message')) ?></textarea>
                    <span class="hint" id="maintenance_message-hint"><?= e(t('settings.maintenance_message_hint')) ?></span>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-secondary"><?= e(t('settings.maintenance_message_save')) ?></button>
                </div>
            </form>
        </div>
