<?php

use App\Support\Url;

/**
 * The pictures themselves: the library screen's grid (O-75), and the only thing the picker
 * asks for. Rendered by admin/index.php inside the full screen, and on its own as the fragment
 * the picker loads — one card, one set of markup, so the two can never drift.
 *
 * $picking is what differs. On the library screen a card is a link to the picture, with the
 * tick the bulk bar counts (visible at rest, as a control must be), what it is used on and
 * whether it is described — the table's columns, said in a line; in the picker it is a button
 * that chooses it, because a picker that navigated away would lose everything typed since the
 * last save.
 *
 * @var list<array{id: int, filename: string, original?: string, ext?: string, size: string, width: int, height: int, complete: bool, thumb: string|null, kind?: string, downloads?: int, pages?: int, site?: bool, described?: string}> $pictures
 * @var string $search
 * @var bool $picking
 * @var string $csrf
 */
?>
<?php if ($pictures === []): ?>
        <div class="empty-state">
            <p><?= e($search === '' ? t('media.empty') : t('media.search_none', ['term' => $search])) ?></p>
        </div>
<?php else: ?>
        <ul class="media-grid<?= $picking ? ' media-grid-picking' : '' ?>">
<?php foreach ($pictures as $picture):
    $file = ($picture['kind'] ?? 'picture') === 'file';
    $name = $picture['filename'] . (($picture['ext'] ?? '') !== '' ? '.' . $picture['ext'] : '');
    ?>
            <li class="media-card<?= $picking ? '' : ' media-card-library' ?>"<?= !$picking && !$file && !$picture['complete'] ? ' data-media-id="' . e((string) $picture['id']) . '" data-media-unfinished' : '' ?>>
<?php if (!$picking): ?>
                <input type="checkbox" class="media-card-pick" name="ids[]" value="<?= e((string) $picture['id']) ?>" form="media-bulk" data-bulk-pick aria-label="<?= e(t('media.bulk.pick', ['name' => $name])) ?>">
<?php endif; ?>
<?php if ($picking): ?>
                <button type="button" class="media-card-link" data-pick="<?= e($picture['id']) ?>" data-pick-name="<?= e($picture['filename']) ?>" data-pick-complete="<?= $picture['complete'] ? '1' : '0' ?>">
<?php else: ?>
                <a class="media-card-link" href="<?= e(Url::admin('media', $picture['id'])) ?>" data-media-thumb="200x200">
<?php endif; ?>
                    <?php /* An empty alt: the filename is the link text right below, so
                             announcing it twice would be noise. */ ?>
<?php if ($file): ?>
                    <span class="media-thumb media-thumb-file" aria-hidden="true"><?= icon(\App\Modules\Media\MediaFileType::icon((string) ($picture['ext'] ?? ''))) ?><span class="media-row-file-ext"><?= e(strtoupper((string) ($picture['ext'] ?? ''))) ?></span></span>
<?php elseif ($picture['thumb'] !== null): ?>
                    <img class="media-thumb" src="<?= e($picture['thumb']) ?>" alt="" width="200" height="200" loading="lazy">
<?php else: ?>
                    <span class="media-thumb media-thumb-none"><?= e(t('media.no_thumb')) ?></span>
<?php endif; ?>
                    <span class="media-name"><?= e($picture['filename']) ?></span>
<?php if ($picking): ?>
                </button>
<?php else: ?>
                </a>
<?php endif; ?>
                <p class="media-facts">
<?php if ($file): ?>
                    <?= e(strtoupper((string) ($picture['ext'] ?? ''))) ?> · <?= e(tn('media.downloads_many', (int) ($picture['downloads'] ?? 0))) ?>
<?php else: ?>
                    <?= e(t('media.dimensions', ['width' => (string) $picture['width'], 'height' => (string) $picture['height']])) ?>
<?php endif; ?>
                    · <?= e($picture['size']) ?>
                </p>
<?php if (!$picking):
    $pages = (int) ($picture['pages'] ?? 0);
    $site = (bool) ($picture['site'] ?? false);
    ?>
                <p class="media-card-status">
<?php if ($pages > 0 || $site): ?>
                    <span><?= e(t('media.grid.used', ['where' => $pages > 0 ? tn('media.used_many', $pages) . ($site ? ' · ' . t('media.used_site') : '') : t('media.used_site')])) ?></span>
<?php else: ?>
                    <span class="media-unused" title="<?= e(t('media.unused_hint')) ?>"><?= e(t('media.grid.unused')) ?></span>
<?php endif; ?>
<?php if (($picture['described'] ?? '') === 'missing'): ?>
                    <a class="badge badge-accent" href="<?= e(Url::admin('media', $picture['id'])) ?>#meta"><?= e(t('media.grid.undescribed')) ?></a>
<?php endif; ?>
                </p>
<?php endif; ?>
<?php if (!$picture['complete'] && !$picking): ?>
                <div class="media-pending" data-media-pending>
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
