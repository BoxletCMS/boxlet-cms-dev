<?php

use App\Support\Url;

/**
 * Provided by AdminView::render().
 *
 * @var list<array{id: int, locale: string, slug: string, title: string, status: string, updated: string, parent: int, depth: int, first: bool, last: bool}> $pages
 * @var array<string, string> $localeLabels code => label
 * @var string $zone the site's time zone, for the Last edited column
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
        <p class="hint"><?= e(t('pages.order_hint')) ?></p>
        <?php /* The drag writes the new sibling order into this form and submits it, so
                 the same request the buttons make is the one a drag makes. No fetch, and
                 the router's CSRF check covers both. */ ?>
        <form method="post" action="<?= e(Url::admin('pages', 'order')) ?>" data-page-order>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="order" value="">
        </form>
        <div class="table-wrap">
            <table class="table page-tree">
                <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden"><?= e(t('pages.col.order')) ?></span></th>
                        <th scope="col"><?= e(t('pages.col.title')) ?></th>
                        <th scope="col"><?= e(t('pages.col.locale')) ?></th>
                        <th scope="col"><?= e(t('pages.col.address')) ?></th>
                        <th scope="col"><?= e(t('pages.col.status')) ?></th>
                        <th scope="col"><?= e(t('pages.col.updated')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('pages.col.actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody data-page-rows>
<?php foreach ($pages as $page): ?>
<?php
    $id = $page['id'];
    $published = $page['status'] === 'published';
    $address = Url::page($page['locale'], $page['slug']);
    // Siblings share a group: a drag may only reorder rows within one, and Up and Down
    // stop at its ends. Keyed on the parent, not the depth — two pages under different
    // parents sit at the same depth, and keying on depth let a drag carry a child into
    // another parent's rows, which the server then refused (PLAN.md D-011).
    $group = $page['locale'] . ':' . $page['parent'];
?>
                    <tr data-page-id="<?= $id ?>" data-page-group="<?= e($group) ?>">
                        <td class="page-order">
                            <span class="drag-handle" data-page-handle aria-hidden="true"><?= icon('grip-vertical') ?></span>
                            <button type="submit" form="page-move-<?= $id ?>" name="move" value="up" title="<?= e(t('pages.move_up')) ?>" class="button button-ghost move-button"<?= $page['first'] ? ' disabled' : '' ?>>
                                <span class="visually-hidden"><?= e(t('pages.move_up')) ?></span><?= icon('arrow-up') ?>
                            </button>
                            <button type="submit" form="page-move-<?= $id ?>" name="move" value="down" title="<?= e(t('pages.move_down')) ?>" class="button button-ghost move-button"<?= $page['last'] ? ' disabled' : '' ?>>
                                <span class="visually-hidden"><?= e(t('pages.move_down')) ?></span><?= icon('arrow-down') ?>
                            </button>
                        </td>
                        <?php /* A class, not style="--depth: n": the admin sends
                                 default-src 'self' with no 'unsafe-inline', so a style
                                 attribute is blocked and every child would render flush
                                 with its parent. Capped at the depth the stylesheet
                                 declares; deeper pages stop indenting rather than
                                 marching off the column. */ ?>
                        <td class="page-name depth-<?= min($page['depth'], 6) ?>">
                            <a href="<?= e(Url::admin('pages', $id)) ?>"><?= e($page['title']) ?></a>
                        </td>
                        <td><?= e($localeLabels[$page['locale']] ?? $page['locale']) ?></td>
                        <td class="address"><?php if ($published): ?><a href="<?= e($address) ?>"><?= e($address) ?></a><?php else: ?><?= e($address) ?><?php endif; ?></td>
                        <td><span class="status status-<?= e($page['status']) ?>"><?= e(t('pages.status.' . $page['status'])) ?></span></td>
                        <td class="date"><?= e(\App\Support\Dates::local($page['updated'], $zone)) ?></td>
                        <td class="row-actions">
                            <form method="post" action="<?= e(Url::admin('pages', $id, 'status')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="status" value="<?= $published ? 'draft' : 'published' ?>">
                                <button type="submit" class="button button-ghost"><?= e(t($published ? 'pages.unpublish' : 'pages.publish')) ?></button>
                            </form>
                            <form method="post" action="<?= e(Url::admin('pages', $id, 'delete')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="button button-ghost button-danger" data-confirm="<?= e(t('pages.delete_confirm', ['title' => $page['title']])) ?>"><?= e(t('pages.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php /* One form per row, outside the table: a form element cannot sit between a
                 tbody and a tr, so the buttons reach it by id instead. */ ?>
<?php foreach ($pages as $page): ?>
        <form method="post" action="<?= e(Url::admin('pages', 'order')) ?>" id="page-move-<?= $page['id'] ?>" class="visually-hidden">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= $page['id'] ?>">
        </form>
<?php endforeach; ?>
<?php endif; ?>
