<?php

use App\Support\Url;

/**
 * Provided by AdminView::render().
 *
 * @var array<int, array<string, mixed>> $pages
 * @var array<string, string> $localeLabels code => label
 * @var string $csrf
 */
?>
        <div class="page-header">
            <h1><?= e(t('pages.title')) ?></h1>
            <a class="button" href="<?= e(Url::admin('pages', 'new')) ?>"><?= e(t('pages.new')) ?></a>
        </div>
<?php if ($pages === []): ?>
        <div class="empty-state">
            <p><?= e(t('pages.empty')) ?></p>
            <a class="button" href="<?= e(Url::admin('pages', 'new')) ?>"><?= e(t('pages.new')) ?></a>
        </div>
<?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('pages.col.title')) ?></th>
                        <th scope="col"><?= e(t('pages.col.locale')) ?></th>
                        <th scope="col"><?= e(t('pages.col.address')) ?></th>
                        <th scope="col"><?= e(t('pages.col.status')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('pages.col.actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($pages as $page): ?>
<?php
    $id = (int) $page['id'];
    $published = $page['status'] === 'published';
    $address = Url::page((string) $page['locale'], (string) $page['slug']);
?>
                    <tr>
                        <td><a href="<?= e(Url::admin('pages', $id)) ?>"><?= e($page['title']) ?></a></td>
                        <td><?= e($localeLabels[(string) $page['locale']] ?? $page['locale']) ?></td>
                        <td class="address"><?php if ($published): ?><a href="<?= e($address) ?>"><?= e($address) ?></a><?php else: ?><?= e($address) ?><?php endif; ?></td>
                        <td><span class="status status-<?= e($page['status']) ?>"><?= e(t('pages.status.' . $page['status'])) ?></span></td>
                        <td class="row-actions">
                            <form method="post" action="<?= e(Url::admin('pages', $id, 'status')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="status" value="<?= $published ? 'draft' : 'published' ?>">
                                <button type="submit" class="button button-ghost"><?= e(t($published ? 'pages.unpublish' : 'pages.publish')) ?></button>
                            </form>
                            <form method="post" action="<?= e(Url::admin('pages', $id, 'delete')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="button button-ghost button-danger" data-confirm="<?= e(t('pages.delete_confirm', ['title' => (string) $page['title']])) ?>"><?= e(t('pages.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
