<?php

use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;
use App\Support\Url;

/**
 * The Design screen: a strip of characters, the eight decisions on the left, a wide
 * sticky preview on the right. Saving is a plain form post; design.js only refreshes
 * the preview and the inline contrast messages while values change.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var string $character character loaded into the form, '' when none
 * @var string $activeCharacter character the site composes new blocks with
 * @var bool $hasBlocks whether applying a composition would overwrite anything
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
$swatch = static fn (string $hex, string $name): string => '<svg viewBox="0 0 10 10" aria-hidden="true">'
    . '<rect width="10" height="10" fill="' . e($hex) . '" data-swatch="' . e($name) . '"/></svg>';
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

        <section class="characters" aria-labelledby="characters-heading">
            <h2 id="characters-heading"><?= e(t('design.presets')) ?></h2>
            <p class="hint"><?= e(t('design.presets_hint')) ?></p>
            <div class="character-strip">
<?php foreach (Presets::names() as $preset): ?>
<?php
    $tokens = Presets::get($preset);
    $palette = Palette::colors($tokens['seed'], $tokens['secondary'], $tokens['surface_contrast']);
    $shape = Presets::COMPOSITION[$preset]['section'];
    $summary = implode(' · ', [
        t('style.width.' . $shape['width']),
        t('style.rhythm.' . $shape['rhythm']),
        t('style.align.' . $shape['align']),
        t('style.divider.' . Presets::dividerAccent($preset)),
    ]);
?>
                <form method="post" action="<?= e(Url::admin('design')) ?>" class="character-card<?= $preset === $activeCharacter ? ' character-current' : '' ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <h3>
                        <?= e(t('design.preset.' . $preset)) ?>
<?php if ($preset === $activeCharacter): ?>
                        <span class="character-badge"><?= e(t('design.preset.current')) ?></span>
<?php endif; ?>
                    </h3>
                    <div class="character-chips" aria-hidden="true">
                        <?= $swatch($palette['accent'], 'preset-' . $preset . '-accent') ?>
                        <?= $swatch($palette['contrast'], 'preset-' . $preset . '-contrast') ?>
                        <?= $swatch($palette['surface'], 'preset-' . $preset . '-surface') ?>
                    </div>
                    <p class="character-shape"><?= e($summary) ?></p>
                    <p class="hint"><?= e(t('design.preset.' . $preset . '_hint')) ?></p>
                    <button type="submit" name="action" value="preset:<?= e($preset) ?>" class="button button-secondary"><?= e(t('design.load_preset')) ?></button>
                </form>
<?php endforeach; ?>
            </div>
        </section>

        <div class="design-workspace">
            <form method="post" action="<?= e(Url::admin('design')) ?>" class="stack design-form" data-design-form data-check-url="<?= e(Url::admin('design', 'check')) ?>" data-preview-url="<?= e(Url::admin('design', 'preview')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="character" value="<?= e($character) ?>">

                <fieldset class="fieldset">
                    <legend><?= e(t('design.colours')) ?></legend>
                    <div class="field">
                        <label for="design-seed"><?= e(t('design.seed')) ?></label>
                        <div class="colour-field">
                            <input type="color" class="colour-input" id="design-seed" name="seed" value="<?= e($decisions['seed']) ?>">
                            <output class="colour-value" for="design-seed" data-colour-for="design-seed"><?= e($decisions['seed']) ?></output>
                        </div>
                        <span class="hint"><?= e(t('design.seed_hint')) ?></span>
                        <?= $error('seed') ?>
                    </div>
                    <div class="field">
                        <label class="checkbox"><input type="checkbox" name="use_secondary" value="1"<?= $decisions['secondary'] !== '' ? ' checked' : '' ?>> <span><?= e(t('design.use_secondary')) ?></span></label>
                        <div class="colour-field">
                            <input type="color" class="colour-input" id="design-secondary" name="secondary" value="<?= e($decisions['secondary'] !== '' ? $decisions['secondary'] : $colors['contrast']) ?>" aria-label="<?= e(t('design.secondary')) ?>">
                            <output class="colour-value" for="design-secondary" data-colour-for="design-secondary"><?= e($decisions['secondary'] !== '' ? $decisions['secondary'] : $colors['contrast']) ?></output>
                        </div>
                        <?= $error('secondary') ?>
                    </div>
                    <div class="field">
                        <label for="design-surface_contrast"><?= e(t('design.surface_contrast')) ?></label>
                        <?= $select('surface_contrast', $labels('surface_contrast', Tokens::SURFACE_CONTRAST)) ?>
                        <?= $error('surface_contrast') ?>
                    </div>
                    <div class="field">
                        <span class="field-label"><?= e(t('design.derived_colours')) ?></span>
                        <ul class="swatches">
<?php foreach ($colors as $name => $hex): ?>
                            <li><?= $swatch($hex, $name) ?><span><?= e(t('design.color.' . $name)) ?></span><code data-swatch-value="<?= e($name) ?>"><?= e($hex) ?></code></li>
<?php endforeach; ?>
                        </ul>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
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
                    <p class="derived"><?= e(t('design.derived_sizes')) ?> <?= e(implode(' · ', $derived['text'])) ?></p>
                </fieldset>

                <fieldset class="fieldset">
                    <legend><?= e(t('design.shape')) ?></legend>
                    <div class="field">
                        <label for="design-spacing"><?= e(t('design.spacing')) ?></label>
                        <?= $select('spacing', $labels('spacing', array_keys(Tokens::SPACING))) ?>
                        <?= $error('spacing') ?>
                    </div>
<?php foreach (['radius' => Tokens::RADIUS, 'shadow' => Tokens::SHADOW, 'container' => array_keys(Tokens::CONTAINER)] as $key => $values): ?>
                    <div class="field">
                        <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                        <?= $select($key, $labels($key, $values)) ?>
                        <?= $error($key) ?>
                    </div>
<?php endforeach; ?>
                    <p class="derived"><?= e(t('design.derived_spacing')) ?> <?= e(implode(' · ', $derived['space'])) ?></p>
                </fieldset>

<?php if ($character !== '' && $hasBlocks): ?>
                <div class="apply-choice">
                    <h2><?= e(t('design.apply.title', ['character' => t('design.preset.' . $character)])) ?></h2>
                    <div class="apply-option">
                        <button type="submit" name="action" value="save" class="button button-secondary"><?= e(t('design.apply.design_only')) ?></button>
                        <span class="hint"><?= e(t('design.apply.design_only_hint')) ?></span>
                    </div>
                    <div class="apply-option">
                        <button type="submit" name="action" value="save_composition" class="button"><?= e(t('design.apply.with_composition')) ?></button>
                        <span class="hint"><?= e(t('design.apply.with_composition_hint')) ?></span>
                    </div>
                </div>
<?php else: ?>
                <div class="apply-choice">
                    <div class="apply-option">
                        <button type="submit" name="action" value="save" class="button"><?= e(t('design.save')) ?></button>
<?php if ($character !== ''): ?>
                        <span class="hint"><?= e(t('design.apply.no_blocks')) ?></span>
<?php endif; ?>
                    </div>
                </div>
<?php endif; ?>
            </form>

            <div class="design-preview">
                <div class="preview-frame">
                    <div class="preview-bar">
                        <span><?= e(t('design.preview.title')) ?></span>
                        <button type="submit" form="design-preview-form" class="button button-quiet" data-preview-button><?= e(t('design.update_preview')) ?></button>
                    </div>
                    <iframe name="design-preview" src="<?= e($previewUrl) ?>" title="<?= e(t('design.preview')) ?>" data-design-preview></iframe>
                </div>
<?php if ($character !== ''): ?>
                <p class="preview-note"><?= e(t('design.preview.composition_note', ['character' => t('design.preset.' . $character)])) ?></p>
<?php else: ?>
                <p class="preview-note"><?= e(t('design.preview.note')) ?></p>
<?php endif; ?>
            </div>
        </div>

        <?php /* Without JavaScript this form sends the current values to the preview frame. */ ?>
        <form id="design-preview-form" method="get" action="<?= e(Url::admin('design', 'preview')) ?>" target="design-preview" class="visually-hidden"></form>
        <script src="<?= e(Url::versioned('assets/design.js')) ?>" defer></script>
