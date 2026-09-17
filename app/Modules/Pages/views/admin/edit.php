<?php

use App\Modules\Design\Composition;
use App\Support\Url;

/**
 * The page editor. Every block's fields are ordinary inputs in one form, so editing and
 * saving work without JavaScript. _end must stay the last field in the form: when PHP
 * cuts input off at max_input_vars it is the field that goes missing.
 *
 * @var array<string, mixed> $page
 * @var string $titleValue
 * @var string $slugValue
 * @var list<array{id: int, title: string, depth: int}> $parents
 * @var list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var string $character the character new blocks are composed with
 * @var \App\Core\Blocks $registry
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures every picture a media field may choose
 * @var string $csrf
 */
$pageId = (int) $page['id'];
$published = $page['status'] === 'published';
$error = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>' : '';
?>
        <div class="page-header">
            <h1><?= e(t('pages.edit')) ?></h1>
            <span class="status status-<?= e($page['status']) ?>"><?= e(t('pages.status.' . $page['status'])) ?></span>
            <a class="button button-secondary" href="<?= e(Url::admin('pages', $pageId)) ?>"><?= e(t('pages.editor.visual')) ?></a>
<?php if ($published): ?>
            <a href="<?= e(Url::page((string) $page['locale'], (string) $page['slug'])) ?>"><?= e(t('pages.view')) ?></a>
<?php endif; ?>
        </div>
        <p class="page-subtitle"><?= e(t('pages.editor.fallback_hint')) ?></p>
<?php if ($notice !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($notice) ?></p>
<?php endif; ?>
        <form method="post" action="<?= e(Url::admin('pages', $pageId)) ?>" class="editor-form" data-page-editor>
            <?php /* First submit button in the form: pressing Enter in a field saves. */ ?>
            <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('pages.save')) ?></button>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="panel editor-meta">
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
                <?php /* The same control the builder has. This form posts to the same
                         route, which reads parent_id and treats a missing one as "no
                         parent", so leaving it out here un-parented the page on every
                         save. A page's parent is changed in page settings and never by
                         dragging (PLAN.md D-011). */ ?>
                <div class="field">
                    <label for="page-parent"><?= e(t('pages.field.parent')) ?></label>
                    <select id="page-parent" name="parent_id">
                        <option value=""><?= e(t('pages.parent.none')) ?></option>
<?php foreach ($parents as $option): ?>
                        <option value="<?= e($option['id']) ?>"<?= (int) ($page['parent_id'] ?? 0) === $option['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', $option['depth']) . $option['title']) ?></option>
<?php endforeach; ?>
                    </select>
                    <?= $error('parent') ?>
                </div>
            </div>

            <div class="editor-section-title">
                <h2><?= e(t('pages.blocks')) ?></h2>
            </div>
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
                <button type="submit" name="action" value="add" class="button button-secondary" data-editor-action="add"><?= e(t('pages.add')) ?></button>
            </div>

            <div class="editor-actions">
                <button type="submit" name="action" value="save" class="button"><?= e(t('pages.save')) ?></button>
                <span class="hint"><?= e(t('pages.save_hint')) ?></span>
            </div>
            <input type="hidden" name="_end" value="1">
        </form>

<?php foreach ($registry->types() as $type): ?>
        <template data-block-template="<?= e($type) ?>">
<?php
    $index = '__INDEX__';
    $block = [
        'id' => null,
        'type' => $type,
        'content' => $registry->normalize($type, []),
        'style' => Composition::style($character, $type),
        'layout' => Composition::layout($registry, $character, $type),
    ];
    require __DIR__ . '/block.php';
?>
        </template>
<?php endforeach; ?>

        <div class="row-actions editor-secondary">
            <form method="post" action="<?= e(Url::admin('pages', $pageId, 'status')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="return" value="edit">
                <input type="hidden" name="status" value="<?= $published ? 'draft' : 'published' ?>">
                <button type="submit" class="button button-secondary"><?= e(t($published ? 'pages.unpublish' : 'pages.publish')) ?></button>
            </form>
            <form method="post" action="<?= e(Url::admin('pages', $pageId, 'delete')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="button button-ghost button-danger" data-confirm="<?= e(t('pages.delete_confirm', ['title' => (string) $page['title']])) ?>"><?= e(t('pages.delete')) ?></button>
            </form>
        </div>
