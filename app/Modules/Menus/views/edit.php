<?php

use App\Support\Url;

/**
 * One menu: its items in order, and the form that adds another.
 *
 * @var array<string, mixed> $menu
 * @var list<array{id: int, parent_id: int|null, depth: int, label: string, target: string,
 *                 page_id: int|null, page_title: string|null, published: bool, broken: bool,
 *                 hidden: bool, first: bool, last: bool}> $items
 * @var list<array{id: int, title: string, status: string}> $pages
 * @var array<string, string> $errors
 * @var string $title
 * @var string $csrf
 */
$menuId = (int) $menu['id'];
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>'
    : '';

/** Top-level items only: one level of submenu, so only these may hold children (D-028). */
$parents = array_values(array_filter($items, static fn (array $item): bool => $item['parent_id'] === null));
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
            <span class="status"><?= e($menu['locale']) ?></span>
            <a class="button button-secondary" href="<?= e(Url::admin('menus')) ?>"><?= e(t('menus.title')) ?></a>
        </div>

        <div class="panel stack">
            <form method="post" action="<?= e(Url::admin('menus', $menuId, 'rename')) ?>" class="stack">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="field">
                    <label for="menu-name"><?= e(t('menus.name')) ?></label>
                    <input type="text" id="menu-name" name="name" maxlength="190" value="<?= e($menu['name']) ?>" required>
                    <?= $error('name') ?>
                </div>
                <?php /* A verb, not the field's own noun. "Name" on a button says what the
                         thing beside it is called, never what pressing it does. */ ?>
                <button type="submit" class="button button-secondary"><?= e(t('menus.rename')) ?></button>
            </form>
        </div>

<?php if ($items !== []): ?>
        <p class="hint"><?= e(t('menus.order_hint')) ?></p>
        <?php /* The drag writes the new sibling order into this form and submits it, so the
                 request a drag makes is the request the buttons make. No fetch, and the
                 router's CSRF check covers both (D-011, the same shape the page list uses). */ ?>
        <form method="post" action="<?= e(Url::admin('menus', $menuId, 'order')) ?>" data-menu-order>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="order" value="">
        </form>
        <div class="table-wrap">
            <table class="table page-tree">
                <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden"><?= e(t('menus.col.order')) ?></span></th>
                        <th scope="col"><?= e(t('menus.col.label')) ?></th>
                        <th scope="col"><?= e(t('menus.col.target')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('menus.col.actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody data-menu-rows>
<?php foreach ($items as $item): ?>
<?php $group = $item['parent_id'] === null ? 'top' : 'child-' . $item['parent_id']; ?>
                    <tr data-menu-item="<?= e($item['id']) ?>" data-menu-group="<?= e($group) ?>">
                        <td class="page-order">
                            <span class="drag-handle" data-menu-handle aria-hidden="true">⋮⋮</span>
                            <button type="submit" form="menu-move-<?= e($item['id']) ?>" name="move" value="up"
                                    class="button button-ghost"<?= $item['first'] ? ' disabled' : '' ?>><?= e(t('menus.move_up')) ?></button>
                            <button type="submit" form="menu-move-<?= e($item['id']) ?>" name="move" value="down"
                                    class="button button-ghost"<?= $item['last'] ? ' disabled' : '' ?>><?= e(t('menus.move_down')) ?></button>
                        </td>
                        <td class="depth-<?= e($item['depth']) ?>"><?= e($item['label']) ?></td>
                        <td>
<?php if ($item['broken']): ?>
                            <?php /* A badge, not a notice. notice-warning is a full-width
                                     block meant for the top of a screen; inside a table cell
                                     it swallowed the row and pushed the label out of line
                                     with it. Same size as the draft badge beside it. */ ?>
                            <span class="status status-draft"><?= e(t('menus.item.broken')) ?></span>
                            <span class="hint"><?= e(t('menus.item.broken_hint')) ?></span>
<?php else: ?>
                            <span class="address"><?= e($item['target']) ?></span>
<?php /* ONLY when there is a page. An address is neither published nor a draft, and the
         first version of this row put a "draft" badge on every plain URL. */ ?>
<?php if ($item['page_id'] !== null && !$item['published']): ?>
                            <span class="status status-draft"><?= e(t('menus.item.draft')) ?></span>
                            <span class="hint"><?= e(t('menus.item.draft_hint')) ?></span>
<?php endif; ?>
<?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= e(Url::admin('menus', $menuId, 'items', $item['id'], 'delete')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="button button-ghost button-danger"><?= e(t('menus.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php /* One form per row, outside the table so a form element cannot land between <tr>s,
         referenced by the buttons' form attribute. The same arrangement the page list uses. */ ?>
<?php foreach ($items as $item): ?>
        <form method="post" action="<?= e(Url::admin('menus', $menuId, 'order')) ?>" id="menu-move-<?= e($item['id']) ?>" class="visually-hidden">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="item" value="<?= e($item['id']) ?>">
        </form>
<?php endforeach; ?>
<?php endif; ?>

        <div class="panel stack">
            <h2><?= e(t('menus.item.add')) ?></h2>
            <form method="post" action="<?= e(Url::admin('menus', $menuId, 'items')) ?>" class="stack">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="field">
                    <label for="item-page"><?= e(t('menus.item.page')) ?></label>
                    <select id="item-page" name="page_id">
                        <option value=""><?= e(t('menus.item.page_none')) ?></option>
<?php foreach ($pages as $page): ?>
                        <option value="<?= e($page['id']) ?>"><?= e($page['title']) ?><?= $page['status'] === 'published' ? '' : ' — ' . e(t('menus.item.draft')) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="item-url"><?= e(t('menus.item.url')) ?></label>
                    <input type="text" id="item-url" name="url" maxlength="2048" aria-describedby="item-url-hint">
                    <span class="hint" id="item-url-hint"><?= e(t('menus.item.url_hint')) ?></span>
                </div>
                <div class="field">
                    <label for="item-label"><?= e(t('menus.item.label')) ?></label>
                    <input type="text" id="item-label" name="label" maxlength="255" aria-describedby="item-label-hint">
                    <span class="hint" id="item-label-hint"><?= e(t('menus.item.label_hint')) ?></span>
                </div>
                <div class="field">
                    <label for="item-parent"><?= e(t('menus.item.parent')) ?></label>
                    <select id="item-parent" name="parent_id">
                        <option value=""><?= e(t('menus.item.parent_top')) ?></option>
<?php foreach ($parents as $parent): ?>
                        <option value="<?= e($parent['id']) ?>"><?= e($parent['label']) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <?= $error('item') ?>
                <button type="submit" class="button"><?= e(t('menus.item.add')) ?></button>
            </form>
        </div>
