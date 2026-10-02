<?php

use App\Core\BlockOptions;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Support\Controls;
use App\Support\Pictogram;

/**
 * THE BLOCK'S INSPECTOR (PLAN.md D-175, README 4.5): Layout, Options, the fields not shown on the
 * page, All content (folded), the way to its section, and its actions.
 *
 * ONE FORM, THE FORM'S OWN NAMES. Every value of the block is an input named as the plain
 * editor names it — `blocks[KEY][field]`, `[layout]`, `[options][name]` — so builder-inspector.js
 * sends the whole form to /fields and the server cleans it with BlockForm::parse(), the parser
 * every save has used. Nothing about a field's shape is written twice. The dot and ↺ compare
 * the layout and each option with what the character answers for the block's type.
 *
 * WHICH FIELD GOES WHERE. Words a visitor reads — a line, a paragraph, rich text, a repeater's
 * items — are "All content", folded: in phase 5 they are typed on the page. A link, a picture,
 * a file, a form and a choice are "Fields not shown on the page": what the page says nowhere
 * as words. Each field is in exactly one of the two.
 *
 * @var array{key: string, id: int|null, type: string, content: array<string, mixed>, style: array<string, string|int|null>, options: array<string, string>, layout: string, section: string, column: int} $block
 * @var array{key: string, id: int|null, layout: string, stack: string, style: array<string, string|int|null>} $section
 * @var string $character
 * @var \App\Core\Blocks $registry
 * @var array<string, string> $errors
 * @var int $number its section's place on the page, from 1
 * @var array{stale: array<int, array{source: int, type: string, content: array<string, mixed>}>, sourceLabel: string}|null $translation
 */
$type = $block['type'];
$definition = $registry->get($type);
$key = $block['key'];
$prefix = 'blocks[' . $key . ']';
$idPrefix = 'ins-b-' . $key . '-';
$reset = static fn (string $path): array => ['form' => '', 'name' => 'reset', 'value' => $path, 'title' => t('controls.reset')];
$sectionName = (string) ($section['style'][SectionStyle::NAME] ?? '');
$sectionName = $sectionName !== '' ? $sectionName : t('builder.section_n', ['n' => $number]);

// ---- Layout ---------------------------------------------------------------------------------
$composedLayout = Composition::layout($registry, $character, $type);
$tiles = [];
foreach ($definition['layouts'] as $layout) {
    $tiles[$layout] = ['label' => t('block.' . $type . '.layout.' . $layout), 'picture' => Pictogram::svg($definition['pictograms'][$layout] ?? [])];
}
$layoutChanged = $block['layout'] !== $composedLayout;
$layoutGroup = count($tiles) > 1
    ? Controls::row(t('pages.layout'), Controls::tiles($prefix . '[layout]', $tiles, $block['layout'], $idPrefix . 'layout-label', $idPrefix . 'layout-'), ['key' => 'b.layout', 'labelId' => $idPrefix . 'layout-label', 'changed' => $layoutChanged, 'readout' => t('block.' . $type . '.layout.' . $block['layout'])] + ($layoutChanged ? ['reset' => $reset('b.layout:' . $composedLayout)] : []))
    : '<input type="hidden" name="' . e($prefix) . '[layout]" value="' . e($block['layout']) . '">';

// ---- Options --------------------------------------------------------------------------------
$specs = $definition['options'];
$shown = BlockOptions::effective($specs, $block['options'], Composition::options($character, $type));
$optionsGroup = '';
foreach ($specs as $name => $spec) {
    $label = 'block.' . $type . '.option.' . $name;
    $stored = $block['options'][$name] ?? '';
    $path = 'b.options.' . $name;
    if ($spec['type'] === 'choice') {
        $labels = [];
        foreach ($spec['values'] as $value) {
            $labels[$value] = short_label($label, $value);
        }
        $control = segmented_group($prefix . '[options][' . $name . ']', $labels, $shown[$name], $idPrefix . 'option-' . $name . '-label', $idPrefix . 'option-' . $name . '-');
    } else {
        $control = Controls::slider($prefix . '[options][' . $name . ']', $idPrefix . 'option-' . $name, $shown[$name], $spec['min'], $spec['max'], $spec['step']);
    }
    $optionsGroup .= Controls::row(t($label), $control, ['key' => $path, 'labelId' => $idPrefix . 'option-' . $name . '-label', 'changed' => $stored !== '', 'readout' => $spec['type'] === 'choice' ? '' : $shown[$name]] + ($stored !== '' ? ['reset' => $reset($path)] : []));
}

// ---- Fields ----------------------------------------------------------------------------------
$words = ['text', 'textarea', 'richtext', 'repeater'];
$hidden = '';
$content = '';
foreach ($definition['fields'] as $declared => $field) {
    ob_start();
    $fieldError = $errors[$declared] ?? null;
    if ($field['type'] === 'repeater') {
        $repeaterName = (string) $declared;
        $repeaterField = $field;
        $items = is_array($block['content'][$declared] ?? null) ? array_values($block['content'][$declared]) : [];
        $index = $key;
        require __DIR__ . '/../repeater.php';
    } else {
        $fieldSpec = $field;
        $fieldKey = 'block.' . $type . '.' . $declared;
        $fieldName = $prefix . '[' . $declared . ']';
        $fieldId = $idPrefix . $declared;
        $fieldValue = $block['content'][$declared] ?? null;
        require __DIR__ . '/../field.php';
    }
    $markup = (string) ob_get_clean();
    if (in_array($field['type'], $words, true)) {
        $content .= $markup;
    } else {
        $hidden .= $markup;
    }
}
$changed = count(array_filter($block['options'], static fn (string $v): bool => $v !== '')) + ($layoutChanged ? 1 : 0);
?>
<div class="inspector-head">
    <span class="inspector-icon" aria-hidden="true"><?= icon((string) ($definition['icon'] ?? 'file-text')) ?></span>
    <div class="inspector-titles">
        <h2 class="inspector-name"><?= e(t('block.' . $type)) ?></h2>
        <p class="inspector-context"><?= e(t('builder.block_context', ['section' => $sectionName])) ?></p>
    </div>
<?php if ($changed > 0): ?>
    <button type="button" class="link-button inspector-reset" data-reset-all="block" data-layout="<?= e($composedLayout) ?>"><?= icon('history') ?> <?= e(t('builder.reset_to_character')) ?></button>
<?php endif; ?>
</div>
<?php
// A translation's block whose source changed since it was translated (D-043, step 3): said
// first, with the source's words, and a button posting the form builder.php keeps for it.
$staleFrom = $translation !== null && $block['id'] !== null ? ($translation['stale'][$block['id']] ?? null) : null;
?>
<?php if ($staleFrom !== null): ?>
<div class="notice notice-warning stale-notice inspector-notice" role="status">
    <p><?= e(t('translations.stale', ['language' => $translation['sourceLabel']])) ?></p>
    <details class="stale-original">
        <summary><?= e(t('translations.show_original', ['language' => $translation['sourceLabel']])) ?></summary>
        <dl>
<?php foreach (\App\Modules\Pages\TranslationStatus::words($registry, $staleFrom['type'], $staleFrom['content']) as $word): ?>
            <dt><?= e($word['label']) ?></dt>
            <dd><?= nl2br(e($word['text'])) ?></dd>
<?php endforeach; ?>
        </dl>
    </details>
    <button type="submit" form="current-<?= e((string) $block['id']) ?>" class="button button-secondary"><?= e(t('translations.mark_current')) ?></button>
</div>
<?php endif; ?>
<?php /* data-block, as the plain editor's group has it: the picker, its cover button, the crop's
         shape and the repeater find their block by it. Without it a video's cover had no button. */ ?>
<form class="inspector-form" data-block data-block-fields="<?= e($key) ?>" novalidate>
    <input type="hidden" name="<?= e($prefix) ?>[type]" value="<?= e($type) ?>">
<?php if (count($tiles) > 1): ?>
    <?= Controls::group('ins-layout', t('builder.group.layout'), $layoutGroup, ['changed' => $layoutChanged ? 1 : 0]) ?>
<?php else: ?>
    <?= $layoutGroup ?>
<?php endif; ?>
<?php if ($optionsGroup !== ''): ?>
    <?= Controls::group('ins-options', t('builder.group.options'), $optionsGroup, ['changed' => count(array_filter($block['options'], static fn (string $v): bool => $v !== ''))]) ?>
<?php endif; ?>
<?php if ($hidden !== ''): ?>
    <?= Controls::group('ins-hidden', t('builder.group.not_shown'), '<p class="hint-line">' . e(t('builder.not_shown_hint')) . '</p>' . $hidden) ?>
<?php endif; ?>
<?php if ($content !== ''): ?>
    <?= Controls::group('ins-content', t('builder.group.all_content'), $content, ['open' => false]) ?>
<?php endif; ?>
</form>
<?= Controls::group('ins-section', t('builder.group.section', ['name' => $sectionName]), '<p class="hint-line">' . e(t('builder.section_edit_hint')) . '</p><button type="button" class="button button-secondary" data-action="select-section">' . icon('arrow-right') . ' ' . e(t('builder.edit_section')) . '</button>') ?>
<div class="inspector-actions">
    <button type="button" class="link-button" data-action="duplicate"><?= icon('copy') ?> <?= e(t('builder.duplicate_block')) ?></button>
    <button type="button" class="link-button link-danger" data-action="delete"><?= icon('trash-2') ?> <?= e(t('builder.delete_block')) ?></button>
</div>
