<?php

/**
 * One field of a block, as the input the form submits.
 *
 * Rendered from two places: a block's own fields (block.php) and the fields of one
 * repeater item (item.php). A partial rather than a copy, because an item's fields ARE
 * ordinary fields — the same rule Blocks::value() and BlockForm::field() follow one level
 * down (PLAN.md O-11). A second copy of this markup is how a field type added later is
 * handled at the top level and quietly forgotten inside items.
 *
 * The label comes from a KEY PREFIX rather than from a block type and a field name, so
 * this file serves `block.hero.heading` and `block.columns.items.heading` without knowing
 * which level it is on (SPEC §5.3).
 *
 * @var array<string, mixed> $fieldSpec  the validated field declaration
 * @var string $fieldKey    label key: block.{type}.{field}, or block.{type}.{field}.{itemfield}
 * @var string $fieldName   the submitted name, e.g. blocks[0][items][1][heading]
 * @var string $fieldId     the input's id, unique on the page
 * @var mixed  $fieldValue  the stored value, already normalized
 * @var string|null $fieldError
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures every picture a media field may choose
 * @var array<int, array{title: string, depth: int, published: bool}> $linkPages page group => what a link field
 *                                                    may point at, in tree order (PLAN.md D-034)
 */
$fieldLabel = t($fieldKey) . ($fieldSpec['required'] ? ' ' . t('pages.required_marker') : '');
?>
                <div class="field">
                    <label for="<?= e($fieldId) ?>"><?= e($fieldLabel) ?></label>
<?php if ($fieldSpec['type'] === 'richtext'): ?>
                    <?php /* The textarea is the real field and carries the name. richtext.js
                             moves the name onto a hidden input and puts the editor above it,
                             so a browser without JavaScript still edits this page, and the
                             plain toggle is simply what is underneath rather than a second
                             input kept in step. */ ?>
                    <?php /* The toolbar is ours now, not the editor's (D-017). Short text
                             labels rather than an invented icon set: legible at rest by
                             construction, which is what D-012 asks for, and one less thing
                             to draw twice. Each button says what it does through title and
                             an accessible label; richtext.js binds them by data-rt. */ ?>
                    <div class="richtext" data-richtext>
                        <div class="richtext-toolbar" data-richtext-toolbar role="toolbar" aria-label="<?= e(t('richtext.toolbar')) ?>">
                            <div class="rt-group">
                                <button type="button" class="rt-button" data-rt="bold" aria-pressed="false" title="<?= e(t('richtext.bold')) ?>"><span aria-hidden="true">B</span><span class="visually-hidden"><?= e(t('richtext.bold')) ?></span></button>
                                <button type="button" class="rt-button rt-italic" data-rt="italic" aria-pressed="false" title="<?= e(t('richtext.italic')) ?>"><span aria-hidden="true">I</span><span class="visually-hidden"><?= e(t('richtext.italic')) ?></span></button>
                                <?php /* Drawn, not written: an emoji renders as an empty box wherever that font is
         missing — measured in the headless browser, where it drew as tofu — and an
         ampersand does not say "link" to anyone. currentColor means the icon inherits
         --ui-ink like every text label beside it, so it carries the same 16.51:1 and
         D-012 needs no separate decision. */ ?>
<button type="button" class="rt-button" data-rt="link" aria-pressed="false" title="<?= e(t('richtext.link')) ?>"><svg class="rt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.5 13.5a4.5 4.5 0 0 0 6.36 0l2.83-2.83a4.5 4.5 0 0 0-6.36-6.36l-1.06 1.06"/><path d="M13.5 10.5a4.5 4.5 0 0 0-6.36 0l-2.83 2.83a4.5 4.5 0 0 0 6.36 6.36l1.06-1.06"/></svg><span class="visually-hidden"><?= e(t('richtext.link')) ?></span></button>
                            </div>
                            <div class="rt-group">
                                <button type="button" class="rt-button" data-rt="h2" aria-pressed="false" title="<?= e(t('richtext.heading_2')) ?>"><span aria-hidden="true">H2</span><span class="visually-hidden"><?= e(t('richtext.heading_2')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="h3" aria-pressed="false" title="<?= e(t('richtext.heading_3')) ?>"><span aria-hidden="true">H3</span><span class="visually-hidden"><?= e(t('richtext.heading_3')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="h4" aria-pressed="false" title="<?= e(t('richtext.heading_4')) ?>"><span aria-hidden="true">H4</span><span class="visually-hidden"><?= e(t('richtext.heading_4')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="quote" aria-pressed="false" title="<?= e(t('richtext.quote')) ?>"><span aria-hidden="true">&#8220;</span><span class="visually-hidden"><?= e(t('richtext.quote')) ?></span></button>
                            </div>
                            <div class="rt-group">
                                <button type="button" class="rt-button" data-rt="bullet" aria-pressed="false" title="<?= e(t('richtext.bullets')) ?>"><span aria-hidden="true">&#8226;</span><span class="visually-hidden"><?= e(t('richtext.bullets')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="ordered" aria-pressed="false" title="<?= e(t('richtext.numbers')) ?>"><span aria-hidden="true">1.</span><span class="visually-hidden"><?= e(t('richtext.numbers')) ?></span></button>
                            </div>
                            <div class="rt-group rt-history">
                                <button type="button" class="rt-button" data-rt="undo" title="<?= e(t('richtext.undo')) ?>"><span aria-hidden="true">&#8630;</span><span class="visually-hidden"><?= e(t('richtext.undo')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="redo" title="<?= e(t('richtext.redo')) ?>"><span aria-hidden="true">&#8631;</span><span class="visually-hidden"><?= e(t('richtext.redo')) ?></span></button>
                            </div>
                        </div>
                        <?php /* In the flow, not over the text, so it never covers what is
                                 being linked. Hidden with the hidden attribute rather than
                                 a class; admin.css makes that attribute win over anything
                                 that would lay the panel out. */ ?>
                        <div class="richtext-link" data-richtext-link hidden>
                            <?php /* A page first, as in a link field (PLAN.md D-034). The
                                     option's value is the stored reference itself, so
                                     richtext.js applies whichever of the two is chosen. */ ?>
                            <select class="rt-link-page" aria-label="<?= e(t('richtext.page')) ?>">
                                <option value=""><?= e(t('pages.field.link_address')) ?></option>
<?php foreach ($linkPages as $group => $choice): ?>
                                <option value="<?= e(\App\Modules\Pages\PageLinks::to($group)) ?>"><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
<?php endforeach; ?>
                            </select>
                            <input type="url" class="rt-link-input" placeholder="<?= e(t('richtext.url_placeholder')) ?>" aria-label="<?= e(t('richtext.url')) ?>">
                            <button type="button" class="button button-secondary" data-rt-link="apply"><?= e(t('richtext.link')) ?></button>
                            <button type="button" class="button button-ghost" data-rt-link="remove"><?= e(t('richtext.unlink')) ?></button>
                        </div>
                        <textarea id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" rows="8" data-richtext-source><?= e($fieldValue) ?></textarea>
                        <div class="richtext-actions">
                            <button type="button" class="button button-ghost js-only" data-richtext-toggle data-label-plain="<?= e(t('richtext.plain')) ?>" data-label-rich="<?= e(t('richtext.rich')) ?>"><?= e(t('richtext.plain')) ?></button>
                            <span class="hint js-only"><?= e(t('richtext.paste_plain')) ?></span>
                        </div>
                    </div>
                    <span class="hint"><?= e(t('pages.field.richtext_hint')) ?></span>
<?php elseif ($fieldSpec['type'] === 'textarea'): ?>
                    <textarea id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" rows="3"><?= e($fieldValue) ?></textarea>
<?php elseif ($fieldSpec['type'] === 'media'): ?>
                    <?php /* A choice, never a number. Without JavaScript this select IS the
                             control: nobody can know that "7" is the harbour photograph, so
                             the id never appears on screen. The picker replaces it when
                             JavaScript runs, and both post the same field, so the server
                             validates one thing (MediaReference, on save). */ ?>
                    <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" data-media-field<?= \App\Modules\Media\MediaReference::pickerAttributes() ?>>
                        <option value=""><?= e(t('pages.field.media_none')) ?></option>
<?php foreach ($pictures as $picture): ?>
                        <option value="<?= e($picture['id']) ?>"<?= $picture['thumb'] === null ? '' : ' data-thumb="' . e($picture['thumb']) . '"' ?><?= (int) $fieldValue === $picture['id'] ? ' selected' : '' ?>><?= e($picture['name']) ?></option>
<?php endforeach; ?>
                    </select>
<?php if ($pictures === []): ?>
                    <span class="hint"><?= e(t('pages.field.media_empty')) ?></span>
<?php endif; ?>
                    <?php /* A new tab, because leaving the editor to add a picture would
                             lose everything typed since the last save. */ ?>
                    <span class="hint"><a href="<?= e(\App\Support\Url::admin('media')) ?>" target="_blank" rel="noopener"><?= e(t('pages.field.media_library')) ?></a></span>
<?php elseif ($fieldSpec['type'] === 'link'):
    // A PAGE FIRST, AN ADDRESS SECOND (PLAN.md D-034). The page is stored as a reference
    // and followed at render, so renaming its address moves the link with it. The typed
    // address is for everything that is not a page, and admin-forms.css hides it while a
    // page is chosen — without a script, because the server ignores it then anyway.
    $linkUrl = (string) ($fieldValue['url'] ?? '');
    $linkGroup = \App\Modules\Pages\PageLinks::reference($linkUrl);
    $linkChoice = $linkGroup === null ? null : ($linkPages[$linkGroup] ?? false);
    $linkAddress = $linkGroup === null ? $linkUrl : '';
    $linkPlaceholder = t($linkGroup === null ? 'pages.field.link_label_input' : 'pages.field.link_label_page');
?>
                    <div class="link-field">
                        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>[page]" aria-label="<?= e($fieldLabel . ': ' . t('pages.field.link_page')) ?>">
                            <option value=""<?= $linkGroup === null ? ' selected' : '' ?>><?= e(t('pages.field.link_address')) ?></option>
<?php if ($linkChoice === false): ?>
                            <option value="<?= e((string) $linkGroup) ?>" selected><?= e(t('pages.field.link_page_gone')) ?></option>
<?php endif; ?>
<?php foreach ($linkPages as $group => $choice): ?>
                            <option value="<?= e((string) $group) ?>"<?= $group === $linkGroup ? ' selected' : '' ?>><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
<?php endforeach; ?>
                        </select>
                        <input type="text" class="link-address" id="<?= e($fieldId) ?>-url" name="<?= e($fieldName) ?>[url]" value="<?= e($linkAddress) ?>" placeholder="<?= e(t('pages.field.link_url_input')) ?>" aria-label="<?= e($fieldLabel . ': ' . t('pages.field.link_url_input')) ?>">
                        <input type="text" id="<?= e($fieldId) ?>-label" name="<?= e($fieldName) ?>[label]" value="<?= e($fieldValue['label'] ?? '') ?>" placeholder="<?= e($linkPlaceholder) ?>" aria-label="<?= e($fieldLabel . ': ' . t('pages.field.link_label_input')) ?>">
                    </div>
<?php if ($linkChoice === false): ?>
                    <span class="hint hint-warning"><?= e(t('pages.field.link_gone_hint')) ?></span>
<?php elseif (is_array($linkChoice) && !$linkChoice['published']): ?>
                    <span class="hint hint-warning"><?= e(t('pages.field.link_draft_hint')) ?></span>
<?php endif; ?>
<?php elseif ($fieldSpec['type'] === 'select'): ?>
                    <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>">
<?php foreach ($fieldSpec['options'] as $option): ?>
                        <option value="<?= e($option) ?>"<?= $option === $fieldValue ? ' selected' : '' ?>><?= e(t($fieldKey . '.' . $option)) ?></option>
<?php endforeach; ?>
                    </select>
<?php else: ?>
                    <input type="text" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" value="<?= e($fieldValue) ?>">
<?php endif; ?>
<?php if ($fieldError !== null): ?>
                    <p class="field-error" role="alert"><?= e($fieldError) ?></p>
<?php endif; ?>
                </div>
