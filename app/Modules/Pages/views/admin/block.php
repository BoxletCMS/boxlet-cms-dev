<?php

use App\Modules\Design\Composition;

/**
 * One block's field group in the page editor. Also rendered with $index '__INDEX__'
 * inside a <template> that admin.js clones. admin.js never knows which fields a block
 * has: it only rewrites blocks[n] and block-n- as groups are added, removed or moved.
 *
 * @var int|string $index
 * @var array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string} $block
 * @var array<string, string> $errors
 * @var string $character the character new blocks are composed with
 * @var \App\Core\Blocks $registry
 */
$known = $registry->has($block['type']);
$prefix = 'blocks[' . $index . ']';
$idPrefix = 'block-' . $index . '-';
// Section style opens when it differs from what the active character would give this
// block, so a hand-tuned section announces itself and a composed one stays quiet.
$composed = $known ? Composition::style($character, $block['type']) : [];
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
    $value = $block['content'][$name] ?? null;
    $inputId = $idPrefix . $name;
    $inputName = $prefix . '[' . $name . ']';
    $label = t('block.' . $block['type'] . '.' . $name) . ($field['required'] ? ' ' . t('pages.required_marker') : '');
    $fieldError = $errors[$index . '.' . $name] ?? null;
?>
                <div class="field">
                    <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
<?php if ($field['type'] === 'richtext'): ?>
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
                        <?php /* In the flow, not over the text: the same rule the Trix link
                                 dialog had to be taught. Hidden with the hidden attribute
                                 rather than a class, so it costs nothing when closed. */ ?>
                        <div class="richtext-link" data-richtext-link hidden>
                            <input type="url" class="rt-link-input" placeholder="<?= e(t('richtext.url_placeholder')) ?>" aria-label="<?= e(t('richtext.url')) ?>">
                            <button type="button" class="button button-secondary" data-rt-link="apply"><?= e(t('richtext.link')) ?></button>
                            <button type="button" class="button button-ghost" data-rt-link="remove"><?= e(t('richtext.unlink')) ?></button>
                        </div>
                        <textarea id="<?= e($inputId) ?>" name="<?= e($inputName) ?>" rows="8" data-richtext-source><?= e($value) ?></textarea>
                        <div class="richtext-actions">
                            <button type="button" class="button button-ghost js-only" data-richtext-toggle data-label-plain="<?= e(t('richtext.plain')) ?>" data-label-rich="<?= e(t('richtext.rich')) ?>"><?= e(t('richtext.plain')) ?></button>
                            <span class="hint js-only"><?= e(t('richtext.paste_plain')) ?></span>
                        </div>
                    </div>
                    <span class="hint"><?= e(t('pages.field.richtext_hint')) ?></span>
<?php elseif ($field['type'] === 'textarea'): ?>
                    <textarea id="<?= e($inputId) ?>" name="<?= e($inputName) ?>" rows="3"><?= e($value) ?></textarea>
<?php elseif ($field['type'] === 'media'): ?>
                    <input type="number" id="<?= e($inputId) ?>" name="<?= e($inputName) ?>" value="<?= e($value) ?>" min="1" step="1">
                    <span class="hint"><?= e(t('pages.field.media_hint')) ?></span>
<?php elseif ($field['type'] === 'link'): ?>
                    <div class="field-row">
                        <input type="text" id="<?= e($inputId) ?>" name="<?= e($inputName) ?>[label]" value="<?= e($value['label'] ?? '') ?>" placeholder="<?= e(t('pages.field.link_label_input')) ?>" aria-label="<?= e($label . ': ' . t('pages.field.link_label_input')) ?>">
                        <input type="text" id="<?= e($inputId) ?>-url" name="<?= e($inputName) ?>[url]" value="<?= e($value['url'] ?? '') ?>" placeholder="<?= e(t('pages.field.link_url_input')) ?>" aria-label="<?= e($label . ': ' . t('pages.field.link_url_input')) ?>">
                    </div>
<?php elseif ($field['type'] === 'select'): ?>
                    <select id="<?= e($inputId) ?>" name="<?= e($inputName) ?>">
<?php foreach ($field['options'] as $option): ?>
                        <option value="<?= e($option) ?>"<?= $option === $value ? ' selected' : '' ?>><?= e(t('block.' . $block['type'] . '.' . $name . '.' . $option)) ?></option>
<?php endforeach; ?>
                    </select>
<?php else: ?>
                    <input type="text" id="<?= e($inputId) ?>" name="<?= e($inputName) ?>" value="<?= e($value) ?>">
<?php endif; ?>
<?php if ($fieldError !== null): ?>
                    <p class="field-error" role="alert"><?= e($fieldError) ?></p>
<?php endif; ?>
                </div>
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
