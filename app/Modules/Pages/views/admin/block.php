<?php

use App\Modules\Design\Composition;

/**
 * One block's field group in the page editor. Also rendered with the key '__INDEX__'
 * inside a <template> that admin.js clones. admin.js never knows which fields a block
 * has: it only rewrites blocks[n] and block-n- as groups are added, removed or moved.
 *
 * One field's markup lives in field.php, because a repeater's items hold the same kind of
 * fields one level down and a second copy is how a field type added later works here and
 * silently does not there (PLAN.md O-11).
 *
 * @var int|string $index which group the panel shows; NOT part of any field name
 * @var array{key: string, id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string, section?: string, column?: int} $block
 * @var array<string, string> $errors
 * @var string $character the character new blocks are composed with
 * @var \App\Core\Blocks $registry
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures every picture a media field may choose
 * @var array{source: array<string, mixed>|null, stale: array<int, array{source: int, type: string, content: array<string, mixed>}>, missing: int, sourceLabel: string}|null $translation set by the builder only; undefined elsewhere
 */
$known = $registry->has($block['type']);
/* NAMED BY THE BLOCK, NOT BY WHERE IT SITS (PLAN.md D-094). `blocks[b42][heading]` rather
   than `blocks[3][heading]`, and errors keyed `b42.heading`. The submitted ORDER still
   decides the order on the page — it always did; the index never carried that meaning —
   and a key goes on meaning the same block once a page is a tree of sections (D-093).
   $index stays, but only as which group the panel currently shows. */
$key = $block['key'];
$prefix = 'blocks[' . $key . ']';
$idPrefix = 'block-' . $key . '-';
/* AND THE SECTION IT STANDS IN (D-098), named the same way: `s7` for one the database
   knows, `m0` for one made in this session. Its fields are a prefix of their own beside the
   block's rather than a wrapper around it — `sections[s7][style][surface]` — because a
   nested name would be rewritten by five things that have nothing to do with sections
   (admin.js's rename regexes, repeater.js, item.php, builder-blocks.js, builder-save.js).
   While a section holds one block this fieldset stands exactly where it always has. */
$sectionKey = $block['section'] ?? \App\Modules\Pages\SectionForm::key(null, 0);
$sectionPrefix = 'sections[' . $sectionKey . ']';
$sectionIdPrefix = 'section-' . $sectionKey . '-';
// The section this block stands in, as the editor holds it. Absent in the <template>s the
// editors clone from, where the block has no section yet — the defaults are what a block
// about to be added gets, and the save mints the section.
$sectionOf = (isset($sections) && is_array($sections) ? $sections : [])[$sectionKey] ?? [
    'id' => null,
    'layout' => \App\Modules\Pages\SectionLayout::ONE,
    'stack' => \App\Modules\Pages\SectionLayout::DEFAULT_STACK,
];
// Section style opens when it differs from what the active character would compose for this
// section, so a hand-tuned one announces itself and a composed one stays quiet. Composed
// from the types the section HOLDS (D-096), which for a section of one block is that block.
$composed = $known ? Composition::section($character, [$block['type']]) : [];

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
<?php
// A translation's block whose source changed since it was translated (D-043, step 3):
// said here, with the source's words, and a button that records it as current. The
// button posts a form outside the builder's own, which HTML cannot nest.
$staleFrom = isset($translation) && $block['id'] !== null ? ($translation['stale'][$block['id']] ?? null) : null;
?>
<?php if ($staleFrom !== null && $known): ?>
                <div class="notice notice-warning stale-notice" role="status">
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
                <input type="hidden" name="<?= e($prefix) ?>[type]" value="<?= e($block['type']) ?>">
<?php if ($block['id'] !== null): ?>
                <input type="hidden" name="<?= e($prefix) ?>[id]" value="<?= e($block['id']) ?>">
<?php endif; ?>
                <?php /* Where it stands: which section, and which of that section's columns
                         (D-098). Two hidden inputs rather than a nesting of every name. */ ?>
                <input type="hidden" name="<?= e($prefix) ?>[section]" value="<?= e($sectionKey) ?>" data-block-section>
                <input type="hidden" name="<?= e($prefix) ?>[column]" value="<?= e((string) ($block['column'] ?? 0)) ?>" data-block-column>
<?php if ($known): ?>
<?php foreach ($registry->get($block['type'])['fields'] as $name => $field): ?>
<?php
    $fieldError = $errors[$key . '.' . $name] ?? null;

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
<?php
    /* WHAT EACH LAYOUT ASKS FOR (D-091), field name => how many items, from the block's own
       declaration. Written onto the options so the editor can top a repeater up when the
       row size is chosen, without anything in the browser having to know that a layout
       called "four" means four. */
    $wants = [];
    foreach ($registry->get($block['type'])['fields'] as $fieldName => $declared) {
        foreach (is_array($declared['per_layout'] ?? null) ? $declared['per_layout'] : [] as $forLayout => $count) {
            $wants[$forLayout][$fieldName] = $count;
        }
    }
?>
<?php if (count($layouts) > 1): ?>
                <div class="field">
                    <label for="<?= e($idPrefix) ?>layout"><?= e(t('pages.layout')) ?></label>
                    <select id="<?= e($idPrefix) ?>layout" name="<?= e($prefix) ?>[layout]">
<?php foreach ($layouts as $layoutOption): ?>
                        <option value="<?= e($layoutOption) ?>"<?= $layoutOption === $block['layout'] ? ' selected' : '' ?><?= isset($wants[$layoutOption]) ? ' data-wants="' . e((string) json_encode($wants[$layoutOption])) . '"' : '' ?>><?= e(t('block.' . $block['type'] . '.layout.' . $layoutOption)) ?></option>
<?php endforeach; ?>
                    </select>
                    <?= field_hint('hint.layout') ?>
                </div>
<?php endif; ?>
                <?php /* data-panel-part names this half of the group so the visual editor can
                         put it behind its own Section tab (D-086). An ATTRIBUTE and nothing
                         else: this view is the plain editor's too, and there the group stays
                         one scroll with the style folded at the foot of it, exactly as it
                         has always been. */ ?>
                <details class="block-style" data-panel-part="section"<?= $block['style'] !== $composed ? ' open' : '' ?>>
                    <summary><?= e(t('style.title')) ?></summary>
<?php if (($sectionOf['id'] ?? null) !== null): ?>
                    <input type="hidden" name="<?= e($sectionPrefix) ?>[id]" value="<?= e((string) $sectionOf['id']) ?>">
<?php endif; ?>
                    <div class="block-style-grid">
                        <?php /* THE ARRANGEMENT FIRST (D-097, D-099), because it is the one
                                 choice here that changes the SHAPE of the band rather than
                                 its colouring, and because it is what the empty column that
                                 asks to be filled comes from. A closed set, never a
                                 percentage — the argument SectionStyle makes about colour. */ ?>
<?php foreach (['layout' => \App\Modules\Pages\SectionLayout::LAYOUTS, 'stack' => \App\Modules\Pages\SectionLayout::STACKS] as $arrangeKey => $arrangeValues): ?>
                        <div class="field">
                            <label for="<?= e($sectionIdPrefix . $arrangeKey) ?>"><?= e(t('style.' . $arrangeKey)) ?></label>
                            <select id="<?= e($sectionIdPrefix . $arrangeKey) ?>" name="<?= e($sectionPrefix) ?>[<?= e($arrangeKey) ?>]" data-section-<?= e($arrangeKey) ?>>
<?php foreach ($arrangeKey === 'layout' ? array_keys($arrangeValues) : $arrangeValues as $arrangeValue): ?>
                                <option value="<?= e($arrangeValue) ?>"<?= ($sectionOf[$arrangeKey] ?? '') === $arrangeValue ? ' selected' : '' ?>><?= e(t('style.' . $arrangeKey . '.' . $arrangeValue)) ?></option>
<?php endforeach; ?>
                            </select>
                            <?= field_hint('hint.style.' . $arrangeKey) ?>
                        </div>
<?php endforeach; ?>
<?php foreach (\App\Modules\Design\SectionStyle::OPTIONS as $styleKey => $styleValues): ?>
                        <div class="field">
                            <label for="<?= e($sectionIdPrefix . 'style-' . $styleKey) ?>"><?= e(t('style.' . $styleKey)) ?></label>
                            <select id="<?= e($sectionIdPrefix . 'style-' . $styleKey) ?>" name="<?= e($sectionPrefix) ?>[style][<?= e($styleKey) ?>]">
<?php foreach ($styleValues as $styleValue): ?>
                                <option value="<?= e($styleValue) ?>"<?= ($block['style'][$styleKey] ?? '') === $styleValue ? ' selected' : '' ?>><?= e(t('style.' . $styleKey . '.' . $styleValue)) ?></option>
<?php endforeach; ?>
                            </select>
                            <?= field_hint('hint.style.' . $styleKey) ?>
                        </div>
<?php endforeach; ?>
                        <?php /* D-024's sixth key. Not part of OPTIONS, because OPTIONS is
                                 what becomes class names on the wrapper and a picture is
                                 rendered, not painted. Offered always rather than only when
                                 the surface is `image`: hiding it would take script, and
                                 this panel works without one. */ ?>
                        <div class="field block-style-picture">
                            <label for="<?= e($sectionIdPrefix . 'style-image') ?>"><?= e(t('style.image')) ?></label>
                            <select id="<?= e($sectionIdPrefix . 'style-image') ?>" name="<?= e($sectionPrefix) ?>[style][<?= e(\App\Modules\Design\SectionStyle::IMAGE) ?>]" data-media-field<?= $pickerAttributes() ?>>
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
                    <button type="submit" name="action" value="up-<?= e($key) ?>" class="button button-ghost" data-editor-action="up"><?= e(t('pages.move_up')) ?></button>
                    <button type="submit" name="action" value="down-<?= e($key) ?>" class="button button-ghost" data-editor-action="down"><?= e(t('pages.move_down')) ?></button>
                    <button type="button" class="button button-ghost button-danger js-only" data-editor-action="remove"><?= e(t('pages.remove')) ?></button>
                    <label class="checkbox no-js-only">
                        <input type="checkbox" name="<?= e($prefix) ?>[_delete]" value="1">
                        <span><?= e(t('pages.remove_on_save')) ?></span>
                    </label>
                </div>
            </fieldset>
