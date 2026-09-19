<?php

// The page list's filters and row menu (PLAN.md D-052). adminSite() comes from
// pages_admin_test.php and createPage() from fixtures.php.

testBothDrivers('the page list narrows by language and by words in the title or address', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', '', 'Welcome');
    createPage($db, 'en', 'about', 'About us');
    createPage($db, 'hr', 'o-nama', 'O nama');

    $all = dispatch('/admin/pages')->body;
    assertContains(e(t('pages.rows', ['count' => '3', 'total' => '3'])), $all, 'every row');
    assertContains('<span class="badge badge-edge">' . e(t('pages.home_badge')) . '</span>', $all, 'the home page marked');

    $croatian = dispatch('/admin/pages?lang=hr')->body;
    assertContains('O nama', $croatian, 'a Croatian page');
    assertTrue(!str_contains($croatian, 'About us'), 'an English one');
    assertContains('data-page-handle', $croatian, 'ordering still offered within one language');

    $found = dispatch('/admin/pages?q=' . rawurlencode('about'))->body;
    assertContains(e(t('pages.rows', ['count' => '1', 'total' => '3'])), $found, 'one match');
    assertTrue(!str_contains($found, 'data-page-handle'), 'ordering offered on a search');

    assertContains(e(t('pages.none_match')), dispatch('/admin/pages?q=nothing-like-this')->body, 'no match said');
    assertContains(e(t('pages.rows', ['count' => '3', 'total' => '3'])), dispatch('/admin/pages?lang=xx')->body, 'an unknown language is all of them');
});

testBothDrivers('deleting a page sits in its row\'s menu, still a form that asks first', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About');

    $list = dispatch('/admin/pages')->body;
    assertContains('<details class="row-menu" data-menu>', $list, 'the menu');
    assertContains('action="/admin/pages/' . $id . '/delete"', $list, 'the delete form');
    assertContains('data-confirm="' . e(t('pages.delete_confirm', ['title' => 'About'])) . '"', $list, 'the question');
});
