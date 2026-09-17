<?php

use App\Support\Url;

/**
 * The picture library. Provided by AdminView::render().
 *
 * @var list<array{id: int, filename: string, original: string, size: string, width: int, height: int, complete: bool, thumb: string|null}> $pictures
 * @var string $search
 * @var array{file: int, request: int, fileLabel: string, requestLabel: string} $limits
 * @var string $csrf
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
        <form class="media-upload" method="post" action="<?= e(Url::admin('media')) ?>" enctype="multipart/form-data"
              data-media-upload
              data-max-file="<?= $limits['file'] ?>"
              data-max-request="<?= $limits['request'] ?>"
              data-file-label="<?= e($limits['fileLabel']) ?>"
              data-request-label="<?= e($limits['requestLabel']) ?>"
              data-too-large="<?= e(t('media.too_large_named')) ?>"
              data-too-large-total="<?= e(t('media.too_large_total')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
                <label for="media-files"><?= e(t('media.upload')) ?></label>
                <input type="file" id="media-files" name="files[]" multiple
                       accept="image/jpeg,image/png,image/webp,image/gif,image/avif" data-media-input>
                <p class="hint"><?= e(t('media.upload_hint')) ?></p>
                <p class="hint"><?= e(t('media.limits', ['file' => $limits['fileLabel'], 'request' => $limits['requestLabel']])) ?></p>
            </div>
            <p class="field-error" data-media-error role="alert" hidden></p>
            <button type="submit" class="button"><?= e(t('media.upload_submit')) ?></button>
        </form>

<?php if ($pictures !== [] || $search !== ''): ?>
        <form class="media-search" method="get" action="<?= e(Url::admin('media')) ?>">
            <div class="field">
                <label for="media-search"><?= e(t('media.search')) ?></label>
                <input type="search" id="media-search" name="q" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="button button-secondary"><?= e(t('media.search_submit')) ?></button>
        </form>
<?php endif; ?>

<?php if ($pictures === []): ?>
        <div class="empty-state">
            <p><?= e($search === '' ? t('media.empty') : t('media.search_none', ['term' => $search])) ?></p>
        </div>
<?php else: ?>
        <ul class="media-grid">
<?php foreach ($pictures as $picture): ?>
            <li class="media-card">
                <a class="media-card-link" href="<?= e(Url::admin('media', $picture['id'])) ?>">
                    <?php /* An empty alt: the filename is the link text right below, so
                             announcing it twice would be noise. */ ?>
<?php if ($picture['thumb'] !== null): ?>
                    <img class="media-thumb" src="<?= e($picture['thumb']) ?>" alt="" width="200" height="200" loading="lazy">
<?php else: ?>
                    <span class="media-thumb media-thumb-none"><?= e(t('media.no_thumb')) ?></span>
<?php endif; ?>
                    <span class="media-name"><?= e($picture['filename']) ?></span>
                </a>
                <p class="media-facts">
                    <?= e(t('media.dimensions', ['width' => (string) $picture['width'], 'height' => (string) $picture['height']])) ?>
                    · <?= e($picture['size']) ?>
                </p>
<?php if (!$picture['complete']): ?>
                <div class="media-pending">
                    <p class="hint"><?= e(t('media.incomplete')) ?></p>
                    <form method="post" action="<?= e(Url::admin('media', $picture['id'], 'finish')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="button button-secondary"><?= e(t('media.finish')) ?></button>
                    </form>
                </div>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
