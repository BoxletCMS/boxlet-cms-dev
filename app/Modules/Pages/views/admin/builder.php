<?php

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
 * @var list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
 * @var array<string, string> $errors
 * @var string|null $notice
 * @var string $character
 * @var \App\Core\Blocks $registry
 * @var string $canvasUrl
 * @var string $insertUrl
 * @var list<array{type: string, label: string, preview: string}> $library
 * @var string $csrf
 */
$pageId = (int) $page['id'];
$published = $page['status'] === 'published';
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
                <div class="builder-title">
                    <label class="visually-hidden" for="page-title"><?= e(t('pages.field.title')) ?></label>
                    <input type="text" id="page-title" name="title" value="<?= e($titleValue) ?>" maxlength="255" required aria-describedby="page-title-error">
                    <span id="page-title-error"><?= $error('title') ?></span>
                </div>
                <?php /* The address travels with the save. Without it every save would
                         send an empty slug, which means "the home page of this language",
                         and would either be refused or quietly move the page. It is
                         edited in the plain editor. */ ?>
                <input type="hidden" name="slug" value="<?= e($slugValue) ?>">

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
                <a class="button button-ghost" href="<?= e(Url::page((string) $page['locale'], (string) $page['slug'])) ?>"><?= e(t('pages.view')) ?></a>
<?php endif; ?>
                <button type="submit" name="action" value="save" class="button"><?= e(t('pages.save')) ?></button>
            </div>

            <div class="builder-body">
                <div class="builder-canvas" data-canvas-frame>
                    <iframe src="<?= e($canvasUrl) ?>" title="<?= e(t('pages.canvas')) ?>" data-canvas></iframe>
                </div>

                <?php /* The scripts cannot call t(), so the strings they show come with them. */ ?>
                <aside class="builder-panel" data-insert-url="<?= e($insertUrl) ?>" data-text-inserting="<?= e(t('pages.inserting')) ?>" data-text-failed="<?= e(t('pages.insert_failed')) ?>">
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
        <script src="<?= e(Url::versioned('assets/builder.js')) ?>" defer></script>
