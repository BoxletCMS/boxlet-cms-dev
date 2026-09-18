<?php

use App\Modules\Pages\Page;
use App\Support\Url;

/**
 * The visual editor shell: a toolbar, the canvas, and a panel beside it.
 *
 * Every block's fields are in this one form, all of them, all the time — the panel only
 * decides which group is on screen. That is what keeps the save path identical to the
 * fallback editor's: same field names, same _end sentinel, same server-side validation.
 *
 * @var array<string, mixed> $page
 * @var string $titleValue
 * @var string $slugValue
 * @var list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var string $character
 * @var \App\Core\Blocks $registry
 * @var string $canvasUrl
 * @var string $insertUrl
 * @var list<array{type: string, label: string, preview: string}> $library
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures every picture a media field may choose
 * @var list<array{id: int, title: string, depth: int}> $parents
 * @var string $csrf
 */
$pageId = (int) $page['id'];
$published = $page['status'] === 'published';
// What is stored, not what a visitor would see — see the note in the fallback editor.
$seo = Page::seo($page);
$error = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>' : '';

// Errors belonging to a field this screen does not show. Block errors are keyed
// "position.field" and appear in their own group; "title" has its own place above. Any
// other key would otherwise be invisible, and the user would be told to fix something
// that is nowhere on screen.
$unattached = [];
foreach ($errors as $key => $message) {
    if ($key !== 'title' && !str_contains($key, '.')) {
        $unattached[] = $message;
    }
}
?>
<?php if ($notice !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($unattached !== []): ?>
        <ul class="notice notice-error" role="alert">
<?php foreach ($unattached as $message): ?>
            <li><?= e($message) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <?php /* Not data-page-editor: that marks the fallback form, whose reordering
                 controls admin.js drives. This form's blocks live in the canvas. */ ?>
        <form method="post" action="<?= e(Url::admin('pages', $pageId)) ?>" class="builder" data-builder>
            <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('pages.save')) ?></button>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <?php /* Tells the save endpoint which editor to re-render if validation fails. */ ?>
            <input type="hidden" name="editor" value="builder">

            <div class="builder-bar">
                <?php /* The name, not a second place to edit it: the page panel owns the
                         title, so only one field named "title" is ever submitted. */ ?>
                <p class="builder-title" data-title-echo><?= e($titleValue !== '' ? $titleValue : t('pages.new')) ?></p>

                <div class="builder-devices" role="group" aria-label="<?= e(t('pages.device.label')) ?>">
<?php foreach (['phone' => '24rem', 'tablet' => '48rem', 'desktop' => '100%'] as $device => $width): ?>
                    <button type="button" class="button button-ghost" data-device="<?= e($device) ?>" data-width="<?= e($width) ?>"<?= $device === 'desktop' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>><?= e(t('pages.device.' . $device)) ?></button>
<?php endforeach; ?>
                </div>

                <?php /* In place so the toolbar does not have to be rearranged for Slice 6. */ ?>
                <label class="builder-locale">
                    <span class="visually-hidden"><?= e(t('pages.field.locale')) ?></span>
                    <select disabled title="<?= e(t('pages.locale_later')) ?>">
                        <option><?= e((string) $page['locale']) ?></option>
                    </select>
                </label>

                <a class="button button-ghost" href="<?= e(Url::admin('pages', $pageId, 'form')) ?>"><?= e(t('pages.editor.fallback')) ?></a>
<?php if ($published): ?>
                <?php /* A new tab: this form holds unsaved work, and navigating away from
                         it to look at the published page would be a poor trade. */ ?>
                <a class="button button-ghost" href="<?= e(Url::page((string) $page['locale'], (string) $page['slug'])) ?>" target="_blank" rel="noopener"><?= e(t('pages.view')) ?></a>
<?php endif; ?>
                <button type="submit" name="action" value="save" class="button"><?= e(t('pages.save')) ?></button>
            </div>

            <div class="builder-body">
                <div class="builder-canvas" data-canvas-frame>
                    <iframe src="<?= e($canvasUrl) ?>" title="<?= e(t('pages.canvas')) ?>" data-canvas></iframe>
                </div>

                <?php /* The scripts cannot call t(), so the strings they show come with them. */ ?>
                <aside class="builder-panel" data-insert-url="<?= e($insertUrl) ?>" data-text-inserting="<?= e(t('pages.inserting')) ?>" data-text-failed="<?= e(t('pages.insert_failed')) ?>">
                    <?php /* Page settings sit above the library because they are short and
                             fixed, while the library is long and scrolls: a scrolling grid
                             above a four-field form would bury the form. Both belong to
                             the "nothing selected" state. */ ?>
                    <div class="panel-page" data-page-settings>
                        <h2><?= e(t('pages.panel.page')) ?></h2>

                        <div class="field">
                            <label for="page-title"><?= e(t('pages.field.title')) ?></label>
                            <input type="text" id="page-title" name="title" value="<?= e($titleValue) ?>" maxlength="255" required aria-describedby="page-title-error">
                            <span id="page-title-error"><?= $error('title') ?></span>
                        </div>

                        <div class="field">
                            <label for="page-slug"><?= e(t('pages.field.slug')) ?></label>
                            <input type="text" id="page-slug" name="slug" value="<?= e($slugValue) ?>" maxlength="100" autocapitalize="off" spellcheck="false" data-slug-field>
                            <span class="hint"><?= e($slugValue === '' ? t('pages.slug.home') : t('pages.slug.auto')) ?></span>
                            <?= $error('slug') ?>
                        </div>

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

                        <div class="field">
                            <label for="page-status"><?= e(t('pages.field.status')) ?></label>
                            <select id="page-status" name="status" data-status-field>
<?php foreach (['draft', 'published'] as $state): ?>
                                <option value="<?= e($state) ?>"<?= (string) $page['status'] === $state ? ' selected' : '' ?>><?= e(t('pages.status.' . $state)) ?></option>
<?php endforeach; ?>
                            </select>
                        </div>

                        <?php /* D-004. Empty when unset, never pre-filled with the page
                                 title — the fallback editor carries the same two fields
                                 and the same note explaining why. */ ?>
                        <div class="field">
                            <label for="page-seo-title"><?= e(t('pages.field.seo_title')) ?></label>
                            <input type="text" id="page-seo-title" name="seo_title" value="<?= e($seo['title']) ?>" maxlength="255" aria-describedby="page-seo-title-hint">
                            <span class="hint" id="page-seo-title-hint"><?= e(t('pages.field.seo_title_hint')) ?></span>
                        </div>

                        <div class="field">
                            <label for="page-seo-description"><?= e(t('pages.field.seo_description')) ?></label>
                            <textarea id="page-seo-description" name="seo_description" rows="2" aria-describedby="page-seo-description-hint"><?= e($seo['description']) ?></textarea>
                            <span class="hint" id="page-seo-description-hint"><?= e(t('pages.field.seo_description_hint')) ?></span>
                        </div>
                    </div>

                    <?php /* Shown while nothing is selected. Each picture is the block
                             itself, rendered by the server (BlockPreview). */ ?>
                    <div class="panel-library" data-library>
                        <h2><?= e(t('pages.library')) ?></h2>
                        <p class="hint"><?= e(t('pages.library_hint')) ?></p>
                        <div class="library-grid">
<?php foreach ($library as $item): ?>
                            <button type="button" class="library-card" data-add-type="<?= e($item['type']) ?>">
                                <span class="library-frame">
                                    <iframe src="<?= e($item['preview']) ?>" title="<?= e($item['label']) ?>" loading="lazy" tabindex="-1" aria-hidden="true"></iframe>
                                </span>
                                <span class="library-name"><?= e($item['label']) ?></span>
                            </button>
<?php endforeach; ?>
                        </div>
                        <p class="hint" data-insert-status role="status"></p>
                    </div>

                    <div class="panel-selected" data-panel-selected hidden>
                        <div class="panel-header">
                            <h2 data-selected-name></h2>
                            <button type="button" class="button button-ghost" data-deselect><?= e(t('pages.panel.done')) ?></button>
                        </div>
                        <?php /* These act on the page in front of you. The identical
                                 controls inside each field group submit the form instead,
                                 which belongs to the plain editor; builder.css hides
                                 them here. */ ?>
                        <div class="panel-actions">
                            <button type="button" class="button button-secondary" data-block-action="up"><?= e(t('pages.move_up')) ?></button>
                            <button type="button" class="button button-secondary" data-block-action="down"><?= e(t('pages.move_down')) ?></button>
                            <button type="button" class="button button-secondary" data-block-action="duplicate"><?= e(t('pages.duplicate')) ?></button>
                            <button type="button" class="button button-ghost button-danger" data-block-action="remove"><?= e(t('pages.remove')) ?></button>
                        </div>
                    </div>

                    <div class="panel-blocks" data-block-groups>
<?php foreach ($blocks as $index => $block): ?>
                        <div class="panel-block" data-block-group="<?= e($index) ?>" hidden>
<?php require __DIR__ . '/block.php'; ?>
                        </div>
<?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <?php /* _end must stay the last field: PHP drops everything past max_input_vars. */ ?>
            <input type="hidden" name="_end" value="1">
        </form>
<?php /* One <template> per repeater, keyed type.field — the same set the fallback editor
         emits, and for the same reason: a <template>'s contents are not live nodes, so
         renumber() never reaches inside one and both indices must stay placeholders until
         repeater.js clones it (PLAN.md O-11). A block inserted into the canvas needs
         nothing extra here, because these cover every type the registry knows. */ ?>
<?php foreach ($registry->types() as $templateType): ?>
<?php foreach ($registry->get($templateType)['fields'] as $templateField => $templateSpec): ?>
<?php if ($templateSpec['type'] !== 'repeater') { continue; } ?>
        <template data-item-template="<?= e($templateType) ?>.<?= e($templateField) ?>">
<?php
    $blockType = $templateType;
    $blockIndex = '__INDEX__';
    $repeaterName = (string) $templateField;
    $repeaterField = $templateSpec;
    $itemIndex = '__ITEM__';
    $itemValue = \App\Core\Blocks::emptyItem($templateSpec);
    require __DIR__ . '/item.php';
?>
        </template>
<?php endforeach; ?>
<?php endforeach; ?>
        <?php /* Deferred, so they run in this order: the shell, then the changes. */ ?>
        <script src="<?= e(Url::versioned('assets/builder.js')) ?>" defer></script>
        <script src="<?= e(Url::versioned('assets/builder-blocks.js')) ?>" defer></script>
