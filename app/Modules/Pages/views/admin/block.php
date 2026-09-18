<?php

use App\Modules\Design\Composition;

/**
 * One block's field group in the page editor. Also rendered with $index '__INDEX__'
 * inside a <template> that admin.js clones. admin.js never knows which fields a block
 * has: it only rewrites blocks[n] and block-n- as groups are added, removed or moved.
 *
 * One field's markup lives in field.php, because a repeater's items hold the same kind of
 * fields one level down and a second copy is how a field type added later works here and
 * silently does not there (PLAN.md O-11).
 *
 * @var int|string $index
 * @var array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string} $block
 * @var array<string, string> $errors
 * @var string $character the character new blocks are composed with
 * @var \App\Core\Blocks $registry
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures every picture a media field may choose
 */
$known = $registry->has($block['type']);
$prefix = 'blocks[' . $index . ']';
$idPrefix = 'block-' . $index . '-';
// Section style opens when it differs from what the active character would give this
// block, so a hand-tuned section announces itself and a composed one stays quiet.
$composed = $known ? Composition::style($character, $block['type']) : [];

// What media-picker.js needs, on the field itself rather than in a script: the admin's CSP
// allows no inline script, and these attributes survive being cloned out of a <template>,
// which a page-level element would not reach.
// The same attributes the site settings screen's pickers carry: one definition, in
// MediaReference, so the two cannot drift apart from each other or from media-picker.js.
$pickerAttributes = static fn (): string => \App\Modules\Media\MediaReference::pickerAttributes();
?>
            <?php /* The name as data, so the visual editor's panel heading does not have
                     to scrape it out of the legend and pick up its drag handle with it. */ ?>
            <fieldset class="block-editor" data-block data-block-label="<?= e($known ? t('block.' . $block['type']) : t('pages.block.unknown', ['type' => $block['type']])) ?>">
                <legend class="block-editor-legend">
                    <span class="drag-handle js-only" data-drag-handle title="<?= e(t('pages.drag')) ?>" aria-hidden="true">&#8942;&#8942;</span>
                    <?= e($known ? t('block.' . $block['type']) : t('pages.block.unknown', ['type' => $block['type']])) ?>
                </legend>
                <div class="block-body">
                <input type="hidden" name="<?= e($prefix) ?>[type]" value="<?= e($block['type']) ?>">
<?php if ($block['id'] !== null): ?>
                <input type="hidden" name="<?= e($prefix) ?>[id]" value="<?= e($block['id']) ?>">
<?php endif; ?>
<?php if ($known): ?>
<?php foreach ($registry->get($block['type'])['fields'] as $name => $field): ?>
<?php
    $fieldError = $errors[$index . '.' . $name] ?? null;

    if ($field['type'] === 'repeater') {
        $repeaterName = (string) $name;
        $repeaterField = $field;
        $stored = $block['content'][$name] ?? null;
        $items = is_array($stored) ? array_values($stored) : [];
        require __DIR__ . '/repeater.php';
        continue;
    }

    $fieldSpec = $field;
    $fieldKey = 'block.' . $block['type'] . '.' . $name;
    $fieldName = $prefix . '[' . $name . ']';
    $fieldId = $idPrefix . $name;
    $fieldValue = $block['content'][$name] ?? null;
    require __DIR__ . '/field.php';
?>
<?php endforeach; ?>
<?php $layouts = $registry->get($block['type'])['layouts']; ?>
<?php if (count($layouts) > 1): ?>
                <div class="field">
                    <label for="<?= e($idPrefix) ?>layout"><?= e(t('pages.layout')) ?></label>
                    <select id="<?= e($idPrefix) ?>layout" name="<?= e($prefix) ?>[layout]">
<?php foreach ($layouts as $layoutOption): ?>
                        <option value="<?= e($layoutOption) ?>"<?= $layoutOption === $block['layout'] ? ' selected' : '' ?>><?= e(t('block.' . $block['type'] . '.layout.' . $layoutOption)) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
<?php endif; ?>
                <details class="block-style"<?= $block['style'] !== $composed ? ' open' : '' ?>>
                    <summary><?= e(t('style.title')) ?></summary>
                    <div class="block-style-grid">
<?php foreach (\App\Modules\Design\SectionStyle::OPTIONS as $styleKey => $styleValues): ?>
                        <div class="field">
                            <label for="<?= e($idPrefix . 'style-' . $styleKey) ?>"><?= e(t('style.' . $styleKey)) ?></label>
                            <select id="<?= e($idPrefix . 'style-' . $styleKey) ?>" name="<?= e($prefix) ?>[style][<?= e($styleKey) ?>]">
<?php foreach ($styleValues as $styleValue): ?>
                                <option value="<?= e($styleValue) ?>"<?= ($block['style'][$styleKey] ?? '') === $styleValue ? ' selected' : '' ?>><?= e(t('style.' . $styleKey . '.' . $styleValue)) ?></option>
<?php endforeach; ?>
                            </select>
                        </div>
<?php endforeach; ?>
                        <?php /* D-024's sixth key. Not part of OPTIONS, because OPTIONS is
                                 what becomes class names on the wrapper and a picture is
                                 rendered, not painted. Offered always rather than only when
                                 the surface is `image`: hiding it would take script, and
                                 this panel works without one. */ ?>
                        <div class="field block-style-picture">
                            <label for="<?= e($idPrefix . 'style-image') ?>"><?= e(t('style.image')) ?></label>
                            <select id="<?= e($idPrefix . 'style-image') ?>" name="<?= e($prefix) ?>[style][<?= e(\App\Modules\Design\SectionStyle::IMAGE) ?>]" data-media-field<?= $pickerAttributes() ?>>
                                <option value=""><?= e(t('pages.field.media_none')) ?></option>
<?php $surfaceImage = (int) ($block['style'][\App\Modules\Design\SectionStyle::IMAGE] ?? 0); ?>
<?php foreach ($pictures as $picture): ?>
                                <option value="<?= e($picture['id']) ?>"<?= $picture['thumb'] === null ? '' : ' data-thumb="' . e($picture['thumb']) . '"' ?><?= $surfaceImage === $picture['id'] ? ' selected' : '' ?>><?= e($picture['name']) ?></option>
<?php endforeach; ?>
                            </select>
                            <span class="hint"><?= e(t('style.image_hint')) ?></span>
                        </div>
                    </div>
                </details>
<?php endif; ?>
                </div>
                <div class="block-editor-controls">
                    <button type="submit" name="action" value="up-<?= e($index) ?>" class="button button-ghost" data-editor-action="up"><?= e(t('pages.move_up')) ?></button>
                    <button type="submit" name="action" value="down-<?= e($index) ?>" class="button button-ghost" data-editor-action="down"><?= e(t('pages.move_down')) ?></button>
                    <button type="button" class="button button-ghost button-danger js-only" data-editor-action="remove"><?= e(t('pages.remove')) ?></button>
                    <label class="checkbox no-js-only">
                        <input type="checkbox" name="<?= e($prefix) ?>[_delete]" value="1">
                        <span><?= e(t('pages.remove_on_save')) ?></span>
                    </label>
                </div>
            </fieldset>
