<?php
/**
 * ONE SECTION'S OWN FIELDS: the columns it holds, what they do on a phone, and the five
 * style keys plus the background picture (PLAN.md D-095, D-097, D-099).
 *
 * ITS OWN PARTIAL, AND THAT IS THE POINT. These fields used to be rendered inside each
 * block's group, which was exact while a section held exactly one block and duplicated
 * every control the moment a second joined — two `<select name="sections[s7][style][surface]">`
 * on one form, editing one not moving the other, and the last in the document deciding what
 * is saved. Nobody would have seen it until they set a surface on the wrong half of a band.
 *
 * TWO PLACEMENTS, ONE DEFINITION. The visual editor renders these into a group of their own
 * and its Section tab (D-086) shows the one belonging to the selected block's section. The
 * plain editor renders them where they have always been, folded at the foot of the first
 * block of the band, so that editor does not change by a pixel.
 *
 * @var array{key: string, id: int|null, layout: string|null, stack: string|null, style: array<string, string|int|null>|null} $sectionOf
 * @var array<string, string|int|null> $composed what the character would compose for this
 *      section, so a hand-tuned one announces itself by being open
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures
 */
$sectionPrefix = 'sections[' . $sectionOf['key'] . ']';
$sectionIdPrefix = 'section-' . $sectionOf['key'] . '-';
$style = \App\Modules\Design\SectionStyle::normalize($sectionOf['style']);
// What media-picker.js needs, on the field itself: the admin's CSP allows no inline script
// and these survive being cloned out of a <template>. Defined here rather than inherited
// from block.php, because the visual editor requires this partial on its own (D-099).
$pickerAttributes = static fn (): string => \App\Modules\Media\MediaReference::pickerAttributes();
?>
                <details class="block-style" data-panel-part="section"<?= $style !== $composed ? ' open' : '' ?>>
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
                                <option value="<?= e($styleValue) ?>"<?= ($style[$styleKey] ?? '') === $styleValue ? ' selected' : '' ?>><?= e(t('style.' . $styleKey . '.' . $styleValue)) ?></option>
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
<?php $surfaceImage = (int) ($style[\App\Modules\Design\SectionStyle::IMAGE] ?? 0); ?>
<?php foreach ($pictures as $picture): ?>
                                <option value="<?= e($picture['id']) ?>"<?= $picture['thumb'] === null ? '' : ' data-thumb="' . e($picture['thumb']) . '"' ?><?= $surfaceImage === $picture['id'] ? ' selected' : '' ?>><?= e($picture['name']) ?></option>
<?php endforeach; ?>
                            </select>
                            <span class="hint"><?= e(t('style.image_hint')) ?></span>
                        </div>
                    </div>
                </details>
