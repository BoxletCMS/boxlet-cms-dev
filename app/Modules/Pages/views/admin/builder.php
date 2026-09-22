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
 * @var list<array{code: string, label: string, page: int|null, current: bool}> $languages
 * @var array{source: array<string, mixed>|null, stale: array<int, array{source: int, type: string, content: array<string, mixed>}>, missing: int, sourceLabel: string} $translation
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
<?php /* Icons, each named for a screen reader and on hover (D-039). */ ?>
<?php foreach (['phone' => ['24rem', 'smartphone'], 'tablet' => ['48rem', 'tablet'], 'desktop' => ['100%', 'monitor']] as $device => [$width, $deviceIcon]): ?>
                    <button type="button" class="button button-ghost button-icon" data-device="<?= e($device) ?>" data-width="<?= e($width) ?>" title="<?= e(t('pages.device.' . $device)) ?>"<?= $device === 'desktop' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>><?= icon($deviceIcon) ?><span class="visually-hidden"><?= e(t('pages.device.' . $device)) ?></span></button>
<?php endforeach; ?>
                </div>

<?php require __DIR__ . '/languages-menu.php'; ?>

                <?php /* The plain editor is for when this one cannot run, so it is offered only
                         then: without a script, this is the way to the page's text (D-039). */ ?>
                <noscript><a class="button button-ghost" href="<?= e(Url::admin('pages', $pageId, 'form')) ?>"><?= e(t('pages.editor.fallback')) ?></a></noscript>
<?php if ($published): ?>
                <?php /* A new tab: this form holds unsaved work, and navigating away from
                         it to look at the published page would be a poor trade. */ ?>
                <a class="button button-ghost button-icon" href="<?= e(Url::page((string) $page['locale'], (string) $page['slug'])) ?>" target="_blank" rel="noopener" title="<?= e(t('pages.view')) ?>"><?= icon('external-link') ?><span class="visually-hidden"><?= e(t('pages.view')) ?></span></a>
<?php endif; ?>
                <button type="submit" name="action" value="save" class="button"><?= e(t('pages.save')) ?></button>
            </div>

            <div class="builder-body">
                <div class="builder-canvas" data-canvas-frame>
                    <iframe src="<?= e($canvasUrl) ?>" title="<?= e(t('pages.canvas')) ?>" data-canvas></iframe>

                    <?php /* A WAY BACK, IN WORDS, AFTER THE ONE ACTION THAT DESTROYS WORK
                             (D-079). The shortcut exists and is not discoverable, and the
                             people who most need it are the ones who do not know it is
                             there. It sits over the canvas rather than in the panel because
                             that is where the block was when it went.
                             Hidden until a script fills it: with none, nothing is removed
                             from the page without a save, so there is nothing to offer. */ ?>
                    <div class="builder-undo" data-undo-strip role="status" hidden>
                        <span data-undo-text></span>
                        <button type="button" class="button button-ghost" data-undo-now><?= e(t('pages.undo')) ?></button>
                    </div>
                </div>

                <?php /* The scripts cannot call t(), so the strings they show come with them. */ ?>
                <aside class="builder-panel" data-insert-url="<?= e($insertUrl) ?>" data-text-inserting="<?= e(t('pages.inserting')) ?>" data-text-failed="<?= e(t('pages.insert_failed')) ?>" data-text-removed="<?= e(t('pages.removed')) ?>">
<?php if ($translation['stale'] !== [] || $translation['missing'] > 0): ?>
                    <?php /* A translation behind its source says so before anything else
                             (D-043, step 3); each stale block also carries its own mark. */ ?>
                    <div class="notice notice-warning" role="status">
<?php if ($translation['stale'] !== []): ?>
                        <p><?= e(t('translations.stale_summary', ['count' => (string) count($translation['stale']), 'language' => $translation['sourceLabel']])) ?></p>
<?php endif; ?>
<?php if ($translation['missing'] > 0): ?>
                        <p><?= e(t('translations.missing', ['count' => (string) $translation['missing'], 'language' => $translation['sourceLabel']])) ?></p>
<?php endif; ?>
                    </div>
<?php endif; ?>
                    <?php /* Page settings sit above the library because they are short and
                             fixed, while the library is long and scrolls: a scrolling grid
                             above a four-field form would bury the form. Both belong to
                             the "nothing selected" state. */ ?>
                    <?php /* Folded away by default, as a block's section style is (D-040): it
                             is set once and then mostly left, and open it pushed the block
                             library below the fold. It opens by itself when one of its
                             fields was refused — page errors are keyed by the field's name,
                             block errors by position and field, so a key without a dot is
                             the page's. */ ?>
<?php $pageRefused = array_filter(array_keys($errors), static fn (string $key): bool => !str_contains($key, '.')) !== []; ?>
                    <details class="panel-page" data-page-settings<?= $pageRefused ? ' open' : '' ?>>
                        <summary><?= e(t('pages.panel.page')) ?></summary>
                        <div class="panel-page-fields">

                        <div class="field">
                            <label for="page-title"><?= e(t('pages.field.title')) ?></label>
                            <input type="text" id="page-title" name="title" value="<?= e($titleValue) ?>" maxlength="255" required aria-describedby="page-title-error">
                            <?= field_hint('hint.page.title') ?>
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
                            <?= field_hint('hint.page.parent') ?>
                            <?= $error('parent') ?>
                        </div>

                        <div class="field">
                            <label for="page-status"><?= e(t('pages.field.status')) ?></label>
                            <select id="page-status" name="status" data-status-field>
<?php foreach (['draft', 'published'] as $state): ?>
                                <option value="<?= e($state) ?>"<?= (string) $page['status'] === $state ? ' selected' : '' ?>><?= e(t('pages.status.' . $state)) ?></option>
<?php endforeach; ?>
                            </select>
                            <?= field_hint('hint.page.status') ?>
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
                    </details>

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
                        <?php /* Moving, copying and removing the block live on the block itself
                                 now, as icons in the canvas (D-040); this panel is its fields.
                                 The identical controls inside each field group belong to the
                                 plain editor, and builder.css hides them here. */ ?>
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
<?php foreach (array_keys($translation['stale']) as $staleId): ?>
        <form method="post" action="<?= e(Url::admin('pages', $pageId, 'blocks', $staleId, 'current')) ?>" id="current-<?= e((string) $staleId) ?>" class="visually-hidden">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        </form>
<?php endforeach; ?>
<?php foreach ($languages as $language): ?>
<?php if ($language['page'] === null): ?>
        <?php /* Outside the builder's form, which HTML cannot nest a form inside; the
                 language menu's buttons reach these by id. */ ?>
        <form method="post" action="<?= e(Url::admin('pages', $pageId, 'translate')) ?>" id="translate-<?= e($language['code']) ?>" class="visually-hidden">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="locale" value="<?= e($language['code']) ?>">
        </form>
<?php endif; ?>
<?php endforeach; ?>
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
        <?php /* Deferred, so they run in this order: the shell, then the changes, then the
                 history — which replaces the shell's own do-nothing commit() (D-079). */ ?>
        <script src="<?= e(Url::versioned('assets/builder.js')) ?>" defer></script>
        <script src="<?= e(Url::versioned('assets/builder-blocks.js')) ?>" defer></script>
        <script src="<?= e(Url::versioned('assets/builder-undo.js')) ?>" defer></script>
