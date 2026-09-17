<?php

// Menus (PLAN.md D-028). A new concern, so a new file: t() merges every file in the
// locale's directory and a key defined twice is a test failure, not a silent winner.

return [
    'menus.title' => 'Menus',
    'menus.intro' => 'What visitors are offered, and what it is called there. A menu belongs to one language.',
    'menus.none' => 'No menus yet.',

    'menus.new' => 'New menu',
    'menus.name' => 'Name',
    'menus.name_hint' => 'For you, not for visitors: Header, Footer.',
    'menus.name_required' => 'Give the menu a name.',
    'menus.name_taken' => 'There is already a menu called that in this language.',
    'menus.locale' => 'Language',
    'menus.create' => 'Create menu',
    'menus.rename' => 'Rename menu',
    'menus.created' => 'Menu created.',
    'menus.renamed' => 'Menu renamed.',
    'menus.deleted' => 'Menu deleted.',
    'menus.delete' => 'Delete',
    'menus.delete_confirm' => 'Delete the menu “:name” and everything in it?',
    'menus.not_found' => 'That menu is not here.',

    'menus.col.name' => 'Menu',
    'menus.col.locale' => 'Language',
    'menus.col.items' => 'Items',
    'menus.col.order' => 'Order',
    'menus.col.label' => 'Shown as',
    'menus.col.target' => 'Goes to',
    'menus.col.actions' => 'Actions',
    'menus.items_count' => ':count items',

    'menus.item.add' => 'Add item',
    'menus.item.added' => 'Item added.',
    'menus.item.saved' => 'Item saved.',
    'menus.item.deleted' => 'Item removed.',
    'menus.item.page' => 'Page',
    'menus.item.page_none' => 'No page — use an address',
    'menus.item.url' => 'Address',
    'menus.item.url_hint' => 'Used when no page is chosen. Starts with /, #, ? or http, https, mailto, tel.',
    'menus.item.url_refused' => 'That address was not accepted, so the item points nowhere yet.',
    'menus.item.label' => 'Shown as',
    'menus.item.label_hint' => 'Empty: the page’s own title.',
    'menus.item.parent' => 'Under',
    'menus.item.parent_top' => 'Top level',
    'menus.item.parent_invalid' => 'That item cannot hold another one under it.',
    'menus.item.needs_target' => 'Choose a page or type an address.',

    // An item whose page was deleted, or whose address was refused. Shown here and left
    // out of the site, so the owner can repoint it rather than hunt for what disappeared.
    'menus.item.broken' => 'Goes nowhere',
    'menus.item.broken_hint' => 'Its page was deleted or its address was not accepted. Visitors do not see it.',
    'menus.item.draft' => 'Page is a draft',
    'menus.item.draft_hint' => 'Visitors do not see this item until the page is published.',

    'menus.order_hint' => 'Drag to reorder, or use Up and Down. A menu can be one level deep.',
    'menus.reordered' => 'Order saved.',
    'menus.reorder_failed' => 'That order could not be saved. Reload the page and try again.',
    'menus.move_up' => 'Move up',
    'menus.move_down' => 'Move down',
];
