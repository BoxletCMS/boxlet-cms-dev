<?php

use App\Support\Url;

/**
 * One picture. Provided by AdminView::render().
 *
 * @var array{id: int, filename: string, original: string, size: string, width: int, height: int, complete: bool, thumb: string|null} $picture
 * @var string|null $preview the uncropped variant, or null while it is still being made
 * @var array{x: int, y: int} $focal
 * @var array<string, array{alt: string, caption: string, suggested: bool}> $meta
 * @var list<array<string, mixed>> $locales
 * @var array<int, string> $usedBy page id => title
 * @var string $added
 * @var string $mime
 * @var string $csrf
 */
?>
        <div class="page-header">
            <h1><?= e($picture['filename']) ?></h1>
            <a class="button button-secondary" href="<?= e(Url::admin('media')) ?>"><?= e(t('media.back')) ?></a>
        </div>

        <div class="media-detail">
            <div class="media-preview">
                <?php /* THE FOCAL POINT IS CHOSEN ON THE UNCROPPED PICTURE. `full` is the
                         only preset that is not cropped, so it is the only one where a
                         click means what it appears to mean — on a cropped preview the
                         edges are already gone and the point would land elsewhere.

                         Without JavaScript the two number fields below are the control,
                         and they are not a fallback bolted on: they are what the form
                         posts either way. media.js only fills them in from a click. */ ?>
                <form method="post" action="<?= e(Url::admin('media', $picture['id'], 'focal')) ?>"
                      data-focal-form data-focal-x="<?= $focal['x'] ?>" data-focal-y="<?= $focal['y'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<?php if ($preview !== null): ?>
                    <div class="focal-frame" data-focal-frame>
                        <img class="focal-image" src="<?= e($preview) ?>" alt="<?= e($picture['original']) ?>">
                        <span class="focal-marker" data-focal-marker aria-hidden="true"></span>
                    </div>
                    <p class="hint"><?= e(t('media.focal_hint')) ?></p>
<?php else: ?>
                    <p class="hint"><?= e(t('media.no_thumb')) ?></p>
<?php endif; ?>
                    <div class="focal-fields">
                        <div class="field">
                            <label for="focal-x"><?= e(t('media.focal_x')) ?></label>
                            <input type="number" id="focal-x" name="x" min="0" max="100" value="<?= $focal['x'] ?>" data-focal-input-x>
                        </div>
                        <div class="field">
                            <label for="focal-y"><?= e(t('media.focal_y')) ?></label>
                            <input type="number" id="focal-y" name="y" min="0" max="100" value="<?= $focal['y'] ?>" data-focal-input-y>
                        </div>
                        <button type="submit" class="button button-secondary"><?= e(t('media.focal_save')) ?></button>
                    </div>
                </form>
            </div>

            <div class="media-side">
                <h2><?= e(t('media.details')) ?></h2>
                <dl class="media-facts-list">
                    <dt><?= e(t('media.original_name')) ?></dt>
                    <dd><?= e($picture['original']) ?></dd>
                    <dt><?= e(t('media.dimensions_label')) ?></dt>
                    <dd><?= e(t('media.dimensions', ['width' => (string) $picture['width'], 'height' => (string) $picture['height']])) ?></dd>
                    <dt><?= e(t('media.size')) ?></dt>
                    <dd><?= e($picture['size']) ?></dd>
                    <dt><?= e(t('media.format')) ?></dt>
                    <dd><?= e($mime) ?></dd>
                    <dt><?= e(t('media.added')) ?></dt>
                    <dd><?= e($added) ?></dd>
                </dl>

                <h2><?= e(t('media.used_by')) ?></h2>
<?php if ($usedBy === []): ?>
                <p class="hint"><?= e(t('media.used_by_none')) ?></p>
<?php else: ?>
                <ul class="media-used">
<?php foreach ($usedBy as $pageId => $pageTitle): ?>
                    <li><a href="<?= e(Url::admin('pages', $pageId)) ?>"><?= e($pageTitle) ?></a></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>

                <h2><?= e(t('media.replace')) ?></h2>
                <?php /* Replacing keeps the id, so every page showing this picture shows
                         the new one with nothing to go and find. */ ?>
                <form class="media-replace" method="post" action="<?= e(Url::admin('media', $picture['id'], 'replace')) ?>" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="field">
                        <label for="replace-file" class="visually-hidden"><?= e(t('media.replace')) ?></label>
                        <input type="file" id="replace-file" name="file"
                               accept="image/jpeg,image/png,image/webp,image/gif,image/avif">
                        <p class="hint"><?= e(t('media.replace_hint')) ?></p>
                    </div>
                    <button type="submit" class="button button-secondary"><?= e(t('media.replace')) ?></button>
                </form>

                <?php /* Deleting is refused while a page still shows the picture, so this
                         button is not hidden when it is in use: the refusal names the
                         pages, which is more use than a control that has quietly gone. */ ?>
                <form class="media-delete" method="post" action="<?= e(Url::admin('media', $picture['id'], 'delete')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="button button-danger"
                            data-confirm="<?= e(t('media.delete_confirm', ['name' => $picture['filename']])) ?>"><?= e(t('media.delete')) ?></button>
                </form>
            </div>
        </div>

        <form class="media-meta" method="post" action="<?= e(Url::admin('media', $picture['id'])) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <h2><?= e(t('media.meaning')) ?></h2>
<?php foreach ($locales as $enabled): ?>
<?php $code = (string) $enabled['code']; ?>
            <fieldset class="fieldset">
                <legend><?= e((string) $enabled['label']) ?></legend>
                <div class="field">
                    <label for="alt-<?= e($code) ?>"><?= e(t('media.alt')) ?></label>
                    <input type="text" id="alt-<?= e($code) ?>" name="alt_<?= e($code) ?>" maxlength="255"
                           value="<?= e($meta[$code]['alt'] ?? '') ?>">
<?php /* A guess says so until the owner confirms it, and the badge REPLACES the ordinary
         hint rather than sitting beside it: two pieces of advice under one field is how a
         screen stops being read (D-025). */ ?>
<?php if ($meta[$code]['suggested'] ?? false): ?>
                    <p class="media-suggested"><?= e(t('media.alt_suggested')) ?></p>
                    <p class="hint"><?= e(t('media.alt_suggested_hint')) ?></p>
<?php else: ?>
                    <p class="hint"><?= e(t('media.alt_hint')) ?></p>
<?php endif; ?>
                </div>
                <div class="field">
                    <label for="caption-<?= e($code) ?>"><?= e(t('media.caption')) ?></label>
                    <textarea id="caption-<?= e($code) ?>" name="caption_<?= e($code) ?>" rows="2"><?= e($meta[$code]['caption'] ?? '') ?></textarea>
                </div>
            </fieldset>
<?php endforeach; ?>
            <button type="submit" class="button"><?= e(t('media.save')) ?></button>
        </form>
