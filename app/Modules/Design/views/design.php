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
 * @var list<array{pair: string, decision: string, ratio: float, required: float, passes: bool, foreground: string, background: string}> $pairs every measured contrast pair
 * @var array{text: array<string, int>, text_phone: int, space: int, section: int, radius: int, container: int, container_rem: float} $readable those same decisions in numbers
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
/*
 * One row of the contrast gauge (D-058): the pair as it would actually look, then its name,
 * then what it measures. The specimen is an SVG rather than a styled box, because the admin's
 * policy forbids a style attribute and its stylesheet may not hold a site colour.
 */
$gaugeRow = static function (array $pair): string {
    $ratio = number_format($pair['ratio'], 2);

    return '<li class="gauge-row' . ($pair['passes'] ? '' : ' gauge-fails') . '" data-pair="' . e($pair['pair']) . '">'
        . '<svg class="gauge-sample" viewBox="0 0 40 24" aria-hidden="true">'
        . '<rect width="40" height="24" fill="' . e($pair['background']) . '" data-pair-background/>'
        . '<text x="20" y="17" text-anchor="middle" font-size="13" fill="' . e($pair['foreground']) . '" data-pair-foreground>' . e(t('design.contrast.sample')) . '</text>'
        . '</svg>'
        . '<span class="gauge-name">' . e(t('design.pair.' . $pair['pair'])) . '</span>'
        . '<span class="gauge-ratio"><span data-pair-ratio>' . e($ratio) . '</span>:1'
        . ' <span class="gauge-verdict" data-pair-verdict data-pass="' . e(t('design.contrast.pass')) . '" data-fail="' . e(t('design.contrast.fail')) . '">'
        . e(t($pair['passes'] ? 'design.contrast.pass' : 'design.contrast.fail')) . '</span></span>'
        . '</li>';
};
/* The six that matter most stand open; the rest fold away — but a pair that FAILS is never
   folded, or the screen would hide the one thing the owner has to act on. */
$openPairs = [];
$foldedPairs = [];
foreach ($pairs as $index => $pair) {
    if ($index < 6 || !$pair['passes']) {
        $openPairs[] = $pair;
        continue;
    }
    $foldedPairs[] = $pair;
}
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
            <form method="post" action="<?= e(Url::admin('design')) ?>" id="design-form" class="stack design-form" data-design-form data-check-url="<?= e(Url::admin('design', 'check')) ?>" data-preview-url="<?= e(Url::admin('design', 'preview')) ?>">
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
                        <?= field_hint('hint.design.secondary') ?>
                        <?= $error('secondary') ?>
                    </div>
                    <div class="field">
                        <label for="design-surface_contrast"><?= e(t('design.surface_contrast')) ?></label>
                        <?= $select('surface_contrast', $labels('surface_contrast', Tokens::SURFACE_CONTRAST)) ?>
                        <?= field_hint('hint.design.surface_contrast') ?>
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

                    <?php /* THE GAUGE (D-058). Until now the screen could only say that a pair
                             FAILED, which made a palette passing by a hundredth look exactly like
                             one passing easily — and nobody can aim at a number they are never
                             shown. Every pair is measured on the server and listed here; Save
                             still refuses the same ones it always did. */ ?>
                    <div class="field gauge" data-gauge>
                        <span class="field-label"><?= e(t('design.contrast.title')) ?></span>
                        <span class="hint"><?= e(t('design.contrast.intro')) ?></span>
                        <ul class="gauge-list" data-gauge-open>
<?php foreach ($openPairs as $pair): ?>
                            <?= $gaugeRow($pair) ?>
<?php endforeach; ?>
                        </ul>
<?php if ($foldedPairs !== []): ?>
                        <details class="gauge-more">
                            <summary><?= e(t('design.contrast.more', ['count' => count($foldedPairs)])) ?></summary>
                            <ul class="gauge-list" data-gauge-folded>
<?php foreach ($foldedPairs as $pair): ?>
                                <?= $gaugeRow($pair) ?>
<?php endforeach; ?>
                            </ul>
                        </details>
<?php endif; ?>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend><?= e(t('design.type')) ?></legend>
                    <div class="field">
                        <label for="design-typography"><?= e(t('design.typography')) ?></label>
                        <?= $select('typography', $typefaces) ?>
                        <?= field_hint('hint.design.typography') ?>
                        <?= $error('typography') ?>
                    </div>
                    <div class="field">
                        <label for="design-scale"><?= e(t('design.scale')) ?></label>
                        <?= $select('scale', $labels('scale', Tokens::SCALES)) ?>
                        <?= field_hint('hint.design.scale') ?>
                        <?= $error('scale') ?>
                    </div>
<?php /* Numbers a person reads, not the compiler's own language: this line used to print
         clamp(2.038rem, 1.508rem + 1.759vw, 2.827rem). The specimen is the preview beside
         it — the admin's own type is fixed and may never follow the site's (D-058). */ ?>
                    <p class="derived"><?= e(t('design.readable.text', [
                        'base' => $readable['text']['base'] . 'px',
                        'headings' => implode(' · ', array_map(static fn (int $px): string => $px . 'px', array_slice($readable['text'], 2))),
                        'phone' => $readable['text_phone'] . 'px',
                    ])) ?></p>
                </fieldset>

                <fieldset class="fieldset">
                    <legend><?= e(t('design.shape')) ?></legend>
                    <div class="field">
                        <label for="design-spacing"><?= e(t('design.spacing')) ?></label>
                        <?= $select('spacing', $labels('spacing', array_keys(Tokens::SPACING))) ?>
                        <?= field_hint('hint.design.spacing') ?>
                        <?= $error('spacing') ?>
                    </div>
<?php foreach (['radius' => Tokens::RADIUS, 'shadow' => Tokens::SHADOW, 'container' => array_keys(Tokens::CONTAINER)] as $key => $values): ?>
                    <div class="field">
                        <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                        <?= $select($key, $labels($key, $values)) ?>
                        <?= field_hint('hint.design.' . $key) ?>
                        <?= $error($key) ?>
                    </div>
<?php endforeach; ?>
                    <p class="derived"><?= e(t('design.readable.space', [
                        'space' => $readable['space'] . 'px',
                        'section' => $readable['section'] . 'px',
                    ])) ?></p>
                    <p class="derived"><?= e(t('design.readable.shape', [
                        'radius' => $readable['radius'] . 'px',
                        'container' => $readable['container'] . 'px',
                    ])) ?></p>
                </fieldset>

                <?php /* Its own group rather than three more controls under Shape (D-031).
                         Shape is about the content — its spacing, corners, shadows and
                         measure; these three are about the page as a whole. Put together
                         they would have turned that group into a drawer for anything left
                         over. */ ?>
                <fieldset class="fieldset">
                    <legend><?= e(t('design.page')) ?></legend>
                    <p class="hint"><?= e(t('design.page_hint')) ?></p>
<?php foreach (['header_width' => Tokens::HEADER_WIDTH, 'boxed' => Tokens::BOXED, 'page_background' => Tokens::PAGE_BACKGROUND] as $key => $values): ?>
                    <div class="field">
                        <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                        <?= $select($key, $labels($key, $values)) ?>
                        <?= field_hint('hint.design.' . $key) ?>
<?php if ($key === 'page_background'): ?>
                        <span class="hint"><?= e(t('design.page_background_hint')) ?></span>
<?php endif; ?>
                        <?= $error($key) ?>
                    </div>
<?php endforeach; ?>
                </fieldset>

            </form>

            <div class="design-preview">
                <div class="preview-frame">
                    <?php /* SAVE IN THE BAR, BESIDE THE PICTURE (D-058). It used to sit at the
                             foot of the left column, which is over 3000px tall: the owner
                             changed a colour, watched the preview, and then had to scroll back
                             past every control to keep it.
                             IN THE BAR RATHER THAN UNDER THE FRAME, and that was measured, not
                             preferred: the column is sticky, so anything below a frame 78vh
                             tall sits past the bottom of the window and STAYS there however far
                             the page is scrolled — the browser suite caught the second button
                             as "not clickable". The bar is the one part of a sticky column that
                             is always in view. The buttons submit the form by name. */ ?>
                    <div class="preview-bar">
                        <span><?= e(t('design.preview.title')) ?></span>
                        <div class="preview-actions">
<?php if ($character !== '' && $hasBlocks): ?>
                            <button type="submit" form="design-form" name="action" value="save" class="button button-secondary"><?= e(t('design.apply.design_only')) ?></button>
                            <button type="submit" form="design-form" name="action" value="save_composition" class="button"><?= e(t('design.apply.with_composition')) ?></button>
<?php else: ?>
                            <button type="submit" form="design-form" name="action" value="save" class="button"><?= e(t('design.save')) ?></button>
<?php endif; ?>
                        </div>
                        <button type="submit" form="design-preview-form" class="button button-quiet" data-preview-button><?= e(t('design.update_preview')) ?></button>
                    </div>
                    <iframe name="design-preview" src="<?= e($previewUrl) ?>" title="<?= e(t('design.preview')) ?>" data-design-preview></iframe>
                </div>
<?php if ($character !== ''): ?>
                <p class="preview-note"><?= e(t('design.preview.composition_note', ['character' => t('design.preset.' . $character)])) ?></p>
<?php else: ?>
                <p class="preview-note"><?= e(t('design.preview.note')) ?></p>
<?php endif; ?>
<?php if ($character !== '' && $hasBlocks): ?>
                <?php /* What each of the two buttons does. Under the frame rather than beside
                         them: both labels already say what they save, and two paragraphs in the
                         bar would push the picture down the screen. */ ?>
                <div class="apply-notes">
                    <p class="hint"><strong><?= e(t('design.apply.design_only')) ?></strong> — <?= e(t('design.apply.design_only_hint')) ?></p>
                    <p class="hint"><strong><?= e(t('design.apply.with_composition')) ?></strong> — <?= e(t('design.apply.with_composition_hint')) ?></p>
                </div>
<?php elseif ($character !== ''): ?>
                <p class="hint"><?= e(t('design.apply.no_blocks')) ?></p>
<?php endif; ?>
            </div>
        </div>

        <?php /* Without JavaScript this form sends the current values to the preview frame. */ ?>
        <form id="design-preview-form" method="get" action="<?= e(Url::admin('design', 'preview')) ?>" target="design-preview" class="visually-hidden"></form>
        <script src="<?= e(Url::versioned('assets/design.js')) ?>" defer></script>
