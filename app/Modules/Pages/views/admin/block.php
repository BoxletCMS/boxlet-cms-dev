<?php
/**
 * One block's field group in the page editor. Also rendered with $index '__INDEX__'
 * inside a <template> that admin.js clones. admin.js never knows which fields a block
 * has: it only rewrites blocks[n] and block-n- as groups are added, removed or moved.
 *
 * @var int|string $index
 * @var array{id: int|null, type: string, content: array<string, mixed>|null} $block
 * @var array<string, string> $errors
 * @var \App\Core\Blocks $registry
 */
$known = $registry->has($block['type']);
$prefix = 'blocks[' . $index . ']';
$idPrefix = 'block-' . $index . '-';
?>
            <fieldset class="block-editor" data-block>
                <legend class="block-editor-legend">
                    <span class="drag-handle js-only" data-drag-handle title="<?= e(t('pages.drag')) ?>" aria-hidden="true">&#8942;&#8942;</span>
                    <?= e($known ? t('block.' . $block['type']) : t('pages.block.unknown', ['type' => $block['type']])) ?>
                </legend>
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
<?php if ($field['type'] === 'textarea' || $field['type'] === 'richtext'): ?>
                    <textarea id="<?= e($inputId) ?>" name="<?= e($inputName) ?>" rows="<?= $field['type'] === 'richtext' ? 8 : 3 ?>"><?= e($value) ?></textarea>
<?php if ($field['type'] === 'richtext'): ?>
                    <span class="hint"><?= e(t('pages.field.richtext_hint')) ?></span>
<?php endif; ?>
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
<?php endif; ?>
                <div class="block-editor-controls">
                    <button type="submit" name="action" value="up-<?= e($index) ?>" class="button button-quiet" data-editor-action="up"><?= e(t('pages.move_up')) ?></button>
                    <button type="submit" name="action" value="down-<?= e($index) ?>" class="button button-quiet" data-editor-action="down"><?= e(t('pages.move_down')) ?></button>
                    <button type="button" class="button button-quiet js-only" data-editor-action="remove"><?= e(t('pages.remove')) ?></button>
                    <label class="checkbox no-js-only">
                        <input type="checkbox" name="<?= e($prefix) ?>[_delete]" value="1">
                        <span><?= e(t('pages.remove_on_save')) ?></span>
                    </label>
                </div>
            </fieldset>
