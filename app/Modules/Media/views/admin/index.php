<?php

use App\Support\Url;

/**
 * The picture library. Provided by AdminView::render().
 *
 * @var list<array{id: int, filename: string, original: string, size: string, width: int, height: int, complete: bool, thumb: string|null}> $pictures
 * @var string $search
 * @var array{file: int, request: int, fileLabel: string, requestLabel: string} $limits
 * @var string $csrf
 *
 * The grid itself is admin/cards.php, shared with the picker's fragment.
 */
?>
        <div class="page-header">
            <h1><?= e(t('media.title')) ?></h1>
        </div>

        <?php /* The size limits travel as data attributes rather than inline script: the
                 admin sends default-src 'self' with no 'unsafe-inline', so a <script> in
                 this page would be dropped and the check would silently never run.

                 media.js refuses an oversized file before the form is submitted. That is
                 the only place it CAN be refused readably: nginx answers a body over
                 client_max_body_size with its own 413 page before PHP runs at all. */ ?>
        <?php /* Adding pictures and finding one, side by side: the two things this screen
                 is for, before the pictures themselves (D-038). */ ?>
        <div class="media-tools">
        <form class="media-upload" method="post" action="<?= e(Url::admin('media')) ?>" enctype="multipart/form-data"
              data-media-upload
              data-max-file="<?= $limits['file'] ?>"
              data-max-request="<?= $limits['request'] ?>"
              data-file-label="<?= e($limits['fileLabel']) ?>"
              data-request-label="<?= e($limits['requestLabel']) ?>"
              data-too-large="<?= e(t('media.too_large_named')) ?>"
              data-too-large-total="<?= e(t('media.too_large_total')) ?>"
              data-uploading="<?= e(t('media.uploading')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="hint"><?= e(t('media.upload_hint')) ?></p>
            <?php /* The whole zone is the file input's label, so a click anywhere in it opens
                     the file chooser without a script; media.js adds dropping and uploads as
                     soon as files are chosen. The input stays in the page, focusable, and the
                     zone shows its focus. */ ?>
            <label class="dropzone" for="media-files" data-dropzone>
                <?= icon('cloud-upload') ?>
                <span class="dropzone-text"><?= e(t('media.drop')) ?> <span class="dropzone-browse"><?= e(t('media.browse')) ?></span></span>
                <span class="dropzone-limits"><?= e(t('media.limits', ['file' => $limits['fileLabel'], 'request' => $limits['requestLabel']])) ?></span>
            </label>
            <input type="file" id="media-files" name="files[]" multiple class="visually-hidden"
                   accept="image/jpeg,image/png,image/webp,image/gif,image/avif" data-media-input>
            <p class="field-error" data-media-error role="alert" hidden></p>
            <?php /* Only without a script, where nothing uploads by itself. */ ?>
            <button type="submit" class="button no-js-only"><?= e(t('media.upload_submit')) ?></button>
        </form>

        <form class="media-search" method="get" action="<?= e(Url::admin('media')) ?>" role="search">
            <div class="field">
                <label for="media-search"><?= e(t('media.search')) ?></label>
                <div class="field-inline">
                    <input type="search" id="media-search" name="q" value="<?= e($search) ?>" aria-describedby="media-search-hint">
                    <button type="submit" class="button button-secondary"><?= icon('search') ?> <?= e(t('media.search_submit')) ?></button>
                </div>
                <span class="hint" id="media-search-hint"><?= e(t('media.search_hint')) ?></span>
            </div>
        </form>
        </div>

<?php /* The grid is its own partial because the picker loads exactly this and nothing
         else. Two copies of a card would drift the moment one gained a detail. */ ?>
<?php $picking = false; ?>
<?php require __DIR__ . '/cards.php'; ?>
