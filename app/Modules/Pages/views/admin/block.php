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
                             moves the name onto a hidden input and puts Trix above it, so a
                             browser without JavaScript still edits this page, and the plain
                             toggle is simply what is underneath rather than a second input
                             kept in step. */ ?>
                    <div class="richtext" data-richtext>
                        <trix-toolbar id="<?= e($inputId) ?>-toolbar" class="richtext-toolbar">
                            <div class="trix-button-row">
                                <span class="trix-button-group trix-button-group--text-tools" data-trix-button-group="text-tools">
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-bold" data-trix-attribute="bold" data-trix-key="b" title="<?= e(t('richtext.bold')) ?>" tabindex="-1"><?= e(t('richtext.bold')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-italic" data-trix-attribute="italic" data-trix-key="i" title="<?= e(t('richtext.italic')) ?>" tabindex="-1"><?= e(t('richtext.italic')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-link" data-trix-attribute="href" data-trix-action="link" data-trix-key="k" title="<?= e(t('richtext.link')) ?>" tabindex="-1"><?= e(t('richtext.link')) ?></button>
                                </span>
                                <?php /* No strike (del) and no code (pre): the whitelist has
                                         neither, and a button whose output is discarded on
                                         save is worse than no button. No attach either —
                                         attachments are Trix's one proprietary format. */ ?>
                                <span class="trix-button-group trix-button-group--block-tools" data-trix-button-group="block-tools">
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-heading-1" data-trix-attribute="heading1" title="<?= e(t('richtext.heading')) ?>" tabindex="-1"><?= e(t('richtext.heading')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-quote" data-trix-attribute="quote" title="<?= e(t('richtext.quote')) ?>" tabindex="-1"><?= e(t('richtext.quote')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-bullet-list" data-trix-attribute="bullet" title="<?= e(t('richtext.bullets')) ?>" tabindex="-1"><?= e(t('richtext.bullets')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-number-list" data-trix-attribute="number" title="<?= e(t('richtext.numbers')) ?>" tabindex="-1"><?= e(t('richtext.numbers')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-decrease-nesting-level" data-trix-action="decreaseNestingLevel" title="<?= e(t('richtext.outdent')) ?>" tabindex="-1"><?= e(t('richtext.outdent')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-increase-nesting-level" data-trix-action="increaseNestingLevel" title="<?= e(t('richtext.indent')) ?>" tabindex="-1"><?= e(t('richtext.indent')) ?></button>
                                </span>
                                <span class="trix-button-group-spacer"></span>
                                <span class="trix-button-group trix-button-group--history-tools" data-trix-button-group="history-tools">
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-undo" data-trix-action="undo" data-trix-key="z" title="<?= e(t('richtext.undo')) ?>" tabindex="-1"><?= e(t('richtext.undo')) ?></button>
                                    <button type="button" class="trix-button trix-button--icon trix-button--icon-redo" data-trix-action="redo" data-trix-key="shift+z" title="<?= e(t('richtext.redo')) ?>" tabindex="-1"><?= e(t('richtext.redo')) ?></button>
                                </span>
                            </div>
                            <?php /* Copied from Trix's own toolbar: the dialog's structure is
                                     what makes the link button work. */ ?>
                            <div class="trix-dialogs" data-trix-dialogs>
                                <div class="trix-dialog trix-dialog--link" data-trix-dialog="href" data-trix-dialog-attribute="href">
                                    <div class="trix-dialog__link-fields">
                                        <input type="url" name="href" class="trix-input trix-input--dialog" placeholder="<?= e(t('richtext.url_placeholder')) ?>" aria-label="<?= e(t('richtext.url')) ?>" data-trix-validate-href required data-trix-input>
                                        <div class="trix-button-group">
                                            <input type="button" class="trix-button trix-button--dialog" value="<?= e(t('richtext.link')) ?>" data-trix-method="setAttribute">
                                            <input type="button" class="trix-button trix-button--dialog" value="<?= e(t('richtext.unlink')) ?>" data-trix-method="removeAttribute">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </trix-toolbar>
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
