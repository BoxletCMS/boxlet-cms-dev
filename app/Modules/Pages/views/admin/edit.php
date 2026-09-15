<?php

use App\Support\Url;

/**
 * The page editor. Every block's fields are ordinary inputs in one form, so editing and
 * saving work without JavaScript. _end must stay the last field in the form: when PHP
 * cuts input off at max_input_vars it is the field that goes missing.
 *
 * @var array<string, mixed> $page
 * @var string $titleValue
 * @var string $slugValue
 * @var list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var \App\Core\Blocks $registry
 * @var string $csrf
 */
$pageId = (int) $page['id'];
$published = $page['status'] === 'published';
$error = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>' : '';
?>
        <div class="page-header">
            <h1><?= e(t('pages.edit')) ?></h1>
            <span class="status status-<?= e($page['status']) ?>"><?= e(t('pages.status.' . $page['status'])) ?></span>
<?php if ($published): ?>
            <a href="<?= e(Url::page((string) $page['locale'], (string) $page['slug'])) ?>"><?= e(t('pages.view')) ?></a>
<?php endif; ?>
        </div>
<?php if ($notice !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($notice) ?></p>
<?php endif; ?>
        <form method="post" action="<?= e(Url::admin('pages', $pageId)) ?>" class="stack" data-page-editor>
            <?php /* First submit button in the form: pressing Enter in a field saves. */ ?>
            <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('pages.save')) ?></button>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
                <label for="page-title"><?= e(t('pages.field.title')) ?></label>
                <input type="text" id="page-title" name="title" value="<?= e($titleValue) ?>" maxlength="255" required>
                <?= $error('title') ?>
            </div>
            <div class="field">
                <label for="page-slug"><?= e(t('pages.field.slug')) ?></label>
                <input type="text" id="page-slug" name="slug" value="<?= e($slugValue) ?>" maxlength="100" autocapitalize="off" spellcheck="false" aria-describedby="page-slug-hint">
                <span class="hint" id="page-slug-hint"><?= e(t('pages.field.slug_hint')) ?></span>
                <?= $error('slug') ?>
            </div>

            <h2><?= e(t('pages.blocks')) ?></h2>
            <div class="block-list" data-block-list>
<?php foreach ($blocks as $index => $block): ?>
<?php require __DIR__ . '/block.php'; ?>
<?php endforeach; ?>
            </div>
<?php if ($blocks === []): ?>
            <p class="hint"><?= e(t('pages.no_blocks')) ?></p>
<?php endif; ?>

            <div class="block-add">
                <div class="field">
                    <label for="add-type"><?= e(t('pages.add_block')) ?></label>
                    <select id="add-type" name="add_type" data-add-type>
<?php foreach ($registry->types() as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(t('block.' . $type)) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="action" value="add" class="button button-quiet" data-editor-action="add"><?= e(t('pages.add')) ?></button>
            </div>

            <div class="editor-actions">
                <button type="submit" name="action" value="save" class="button"><?= e(t('pages.save')) ?></button>
            </div>
            <input type="hidden" name="_end" value="1">
        </form>

<?php foreach ($registry->types() as $type): ?>
        <template data-block-template="<?= e($type) ?>">
<?php
    $index = '__INDEX__';
    $block = ['id' => null, 'type' => $type, 'content' => $registry->normalize($type, []), 'style' => [], 'layout' => $registry->layout($type, null)];
    require __DIR__ . '/block.php';
?>
        </template>
<?php endforeach; ?>

        <div class="row-actions editor-secondary">
            <form method="post" action="<?= e(Url::admin('pages', $pageId, 'status')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="edit">
                <input type="hidden" name="status" value="<?= $published ? 'draft' : 'published' ?>">
                <button type="submit" class="button button-quiet"><?= e(t($published ? 'pages.unpublish' : 'pages.publish')) ?></button>
            </form>
            <form method="post" action="<?= e(Url::admin('pages', $pageId, 'delete')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="button button-quiet" data-confirm="<?= e(t('pages.delete_confirm', ['title' => (string) $page['title']])) ?>"><?= e(t('pages.delete')) ?></button>
            </form>
        </div>
