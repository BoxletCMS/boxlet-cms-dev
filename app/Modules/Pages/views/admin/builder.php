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
 * @var string $csrf
 */
$pageId = (int) $page['id'];
$published = $page['status'] === 'published';
$error = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>' : '';
?>
<?php if ($notice !== null): ?>
        <p class="notice notice-error" role="alert"><?= e($notice) ?></p>
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

                <aside class="builder-panel" aria-live="polite">
                    <div class="panel-empty" data-panel-empty>
                        <h2><?= e(t('pages.panel.nothing_selected')) ?></h2>
                        <p class="hint"><?= e(t('pages.panel.nothing_selected_hint')) ?></p>
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
