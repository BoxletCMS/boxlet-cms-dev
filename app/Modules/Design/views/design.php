<?php

use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;
use App\Support\Url;

/**
 * The Design screen: characters (layer 0) and the eight decisions (layer 1) with a live
 * preview. Saving is an ordinary form post; design.js only refreshes the preview and the
 * inline contrast messages while values change.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var array<string, string> $colors derived palette
 * @var array<string, array<string, string>> $derived every derived token
 * @var string $previewUrl
 * @var string $csrf
 */
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field-error" data-error-for="' . e($key) . '" role="alert">' . e($errors[$key]) . '</p>'
    : '<p class="field-error" data-error-for="' . e($key) . '" hidden></p>';
$select = static function (string $key, array $labels) use ($decisions): string {
    $html = '<select id="design-' . e($key) . '" name="' . e($key) . '">';
    foreach ($labels as $value => $label) {
        $selected = (string) $value === $decisions[$key] ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $selected . '>' . e($label) . '</option>';
    }

    return $html . '</select>';
};
$labels = static function (string $key, array $values): array {
    $result = [];
    foreach ($values as $value) {
        $result[$value] = t('design.' . $key . '.' . $value);
    }

    return $result;
};
$typefaces = [];
foreach (Typography::PAIRINGS as $name => $pairing) {
    $heading = Typography::FAMILIES[$pairing['heading']]['name'];
    $body = Typography::FAMILIES[$pairing['body']]['name'];
    $typefaces[$name] = t('design.typography.' . $name) . ': ' . ($heading === $body ? $heading : $heading . ' / ' . $body);
}
?>
        <div class="page-header">
            <h1><?= e(t('design.title')) ?></h1>
        </div>
<?php if ($notice !== null): ?>
        <p class="notice<?= $errors !== [] ? ' notice-error' : '' ?>" role="<?= $errors !== [] ? 'alert' : 'status' ?>"><?= e($notice) ?></p>
<?php endif; ?>

        <section class="design-presets" aria-labelledby="design-presets-heading">
            <h2 id="design-presets-heading"><?= e(t('design.presets')) ?></h2>
            <p class="hint"><?= e(t('design.presets_hint')) ?></p>
            <div class="preset-grid">
<?php foreach (array_keys(Presets::ALL) as $preset): ?>
                <form method="post" action="<?= e(Url::admin('design')) ?>" class="preset-card">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="preset-frame">
                        <iframe src="<?= e(Url::withQuery(Url::admin('design', 'preview'), ['preset' => $preset, 'specimen' => '1'])) ?>" title="<?= e(t('design.preset.' . $preset)) ?>" loading="lazy" tabindex="-1"></iframe>
                    </div>
                    <h3><?= e(t('design.preset.' . $preset)) ?></h3>
                    <p class="hint"><?= e(t('design.preset.' . $preset . '_hint')) ?></p>
                    <button type="submit" name="action" value="preset:<?= e($preset) ?>" class="button button-quiet"><?= e(t('design.load_preset')) ?></button>
                </form>
<?php endforeach; ?>
            </div>
        </section>

        <div class="design-workspace">
            <form method="post" action="<?= e(Url::admin('design')) ?>" class="stack design-form" data-design-form data-check-url="<?= e(Url::admin('design', 'check')) ?>" data-preview-url="<?= e(Url::admin('design', 'preview')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <fieldset>
                    <legend><?= e(t('design.colours')) ?></legend>
                    <div class="field">
                        <label for="design-seed"><?= e(t('design.seed')) ?></label>
                        <input type="color" id="design-seed" name="seed" value="<?= e($decisions['seed']) ?>">
                        <span class="hint"><?= e(t('design.seed_hint')) ?></span>
                        <?= $error('seed') ?>
                    </div>
                    <div class="field">
                        <label class="checkbox"><input type="checkbox" name="use_secondary" value="1"<?= $decisions['secondary'] !== '' ? ' checked' : '' ?>> <span><?= e(t('design.use_secondary')) ?></span></label>
                        <input type="color" id="design-secondary" name="secondary" value="<?= e($decisions['secondary'] !== '' ? $decisions['secondary'] : $colors['contrast']) ?>" aria-label="<?= e(t('design.secondary')) ?>">
                        <?= $error('secondary') ?>
                    </div>
                    <div class="field">
                        <label for="design-surface_contrast"><?= e(t('design.surface_contrast')) ?></label>
                        <?= $select('surface_contrast', $labels('surface_contrast', Tokens::SURFACE_CONTRAST)) ?>
                        <?= $error('surface_contrast') ?>
                    </div>
                    <p class="hint"><?= e(t('design.derived_colours')) ?></p>
                    <ul class="swatches">
<?php foreach ($colors as $name => $hex): ?>
                        <li><svg viewBox="0 0 10 10" aria-hidden="true"><rect width="10" height="10" fill="<?= e($hex) ?>" data-swatch="<?= e($name) ?>"/></svg><span><?= e(t('design.color.' . $name)) ?></span><code data-swatch-value="<?= e($name) ?>"><?= e($hex) ?></code></li>
<?php endforeach; ?>
                    </ul>
                </fieldset>

                <fieldset>
                    <legend><?= e(t('design.type')) ?></legend>
                    <div class="field">
                        <label for="design-typography"><?= e(t('design.typography')) ?></label>
                        <?= $select('typography', $typefaces) ?>
                        <?= $error('typography') ?>
                    </div>
                    <div class="field">
                        <label for="design-scale"><?= e(t('design.scale')) ?></label>
                        <?= $select('scale', $labels('scale', Tokens::SCALES)) ?>
                        <?= $error('scale') ?>
                    </div>
                    <p class="hint"><?= e(t('design.derived_sizes')) ?> <code><?= e(implode(' · ', $derived['text'])) ?></code></p>
                </fieldset>

                <fieldset>
                    <legend><?= e(t('design.shape')) ?></legend>
                    <div class="field">
                        <label for="design-spacing"><?= e(t('design.spacing')) ?></label>
                        <?= $select('spacing', $labels('spacing', array_keys(Tokens::SPACING))) ?>
                        <?= $error('spacing') ?>
                        <span class="hint"><?= e(t('design.derived_spacing')) ?> <code><?= e(implode(' · ', $derived['space'])) ?></code></span>
                    </div>
<?php foreach (['radius' => Tokens::RADIUS, 'shadow' => Tokens::SHADOW, 'container' => array_keys(Tokens::CONTAINER)] as $key => $values): ?>
                    <div class="field">
                        <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                        <?= $select($key, $labels($key, $values)) ?>
                        <?= $error($key) ?>
                    </div>
<?php endforeach; ?>
                </fieldset>

                <div class="editor-actions">
                    <button type="submit" name="action" value="save" class="button"><?= e(t('design.save')) ?></button>
                    <button type="submit" formaction="<?= e(Url::admin('design', 'preview')) ?>" formmethod="get" formtarget="design-preview" class="button button-quiet" data-preview-button><?= e(t('design.update_preview')) ?></button>
                </div>
            </form>

            <div class="design-preview">
                <iframe name="design-preview" src="<?= e($previewUrl) ?>" title="<?= e(t('design.preview')) ?>" data-design-preview></iframe>
            </div>
        </div>
        <script src="<?= e(Url::asset('assets/design.js')) ?>" defer></script>
