<?php

use App\Core\Settings;

// The library as a table (PLAN.md D-052): what uses each picture, whether it is described,
// and the filters. adminSite() is in pages_admin_test.php, referenceMedia() in
// media_reference_test.php, libraryFor() in media_library_test.php.

testBothDrivers('Used on counts pages, a column\'s picture included, and the site\'s own pictures', function (string $driver) {
    $db = adminSite($driver);
    $hero = referenceMedia($db, str_repeat('1', 40));
    $column = referenceMedia($db, str_repeat('2', 40));
    $logo = referenceMedia($db, str_repeat('3', 40));
    $spare = referenceMedia($db, str_repeat('4', 40));
    createPage($db, 'en', 'a', 'A', true, [['type' => 'hero', 'content' => ['heading' => 'A', 'image' => $hero]]]);
    createPage($db, 'en', 'b', 'B', true, [
        ['type' => 'hero', 'content' => ['heading' => 'B', 'image' => $hero]],
        ['type' => 'cards', 'content' => ['heading' => 'C', 'items' => [['image' => $column, 'heading' => 'One']]]],
    ]);
    Settings::set($db, 'site_logo', $logo);

    $usage = libraryFor($db)->usage();
    assertEquals(['pages' => 2, 'site' => false], $usage[$hero] ?? null, 'on two pages');
    assertEquals(['pages' => 1, 'site' => false], $usage[$column] ?? null, 'in a column');
    assertEquals(['pages' => 0, 'site' => true], $usage[$logo] ?? null, 'the logo');
    assertTrue(!isset($usage[$spare]), 'used nowhere');
});

testBothDrivers('the library filters to the unused and the undescribed', function (string $driver) {
    $db = adminSite($driver);
    $used = referenceMedia($db, str_repeat('5', 40));
    $spare = referenceMedia($db, str_repeat('6', 40));
    $db->query('UPDATE media SET filename = ? WHERE id = ?', ['used-one', $used]);
    $db->query('UPDATE media SET filename = ? WHERE id = ?', ['spare-one', $spare]);
    createPage($db, 'en', 'a', 'A', true, [['type' => 'hero', 'content' => ['heading' => 'A', 'image' => $used]]]);
    $db->query("INSERT INTO media_meta (media_id, locale, alt, caption, alt_suggested) VALUES (?, 'en', 'A photo', '', 0)", [$used]);

    $all = dispatch('/admin/media')->body;
    assertContains('used-one.jpg', $all, 'a picture in the table');
    assertContains(e(t('media.used_one')), $all, 'used on one page');
    assertContains(e(t('media.described.missing')), $all, 'one not described');

    $unused = dispatch('/admin/media?show=unused')->body;
    assertContains('spare-one.jpg', $unused, 'the unused one');
    assertTrue(!str_contains($unused, 'used-one.jpg'), 'the used one');

    $undescribed = dispatch('/admin/media?show=undescribed')->body;
    assertContains('spare-one.jpg', $undescribed, 'the undescribed one');
    assertTrue(!str_contains($undescribed, 'used-one.jpg'), 'the described one');
});

// Fifty a page (the owner, 2026-09-27). The list stopped at the newest 200 without a word.
testBothDrivers('the library shows fifty at a time, newest first, and pages to the rest', function (string $driver) {
    $db = adminSite($driver);
    $ids = [];
    for ($n = 1; $n <= 55; $n++) {
        $ids[$n] = referenceMedia($db, sha1('page-' . $n));
        $db->query('UPDATE media SET filename = ? WHERE id = ?', [sprintf('photo-%02d', $n), $ids[$n]]);
    }

    $first = dispatch('/admin/media')->body;
    assertEquals(50, substr_count($first, 'class="media-row"'), 'rows on the first page');
    assertContains('photo-55', $first, 'the newest is first');
    assertTrue(!str_contains($first, 'photo-05<'), 'the oldest is on the first page');
    assertContains(e(t('media.page_of', ['page' => '1', 'pages' => '2', 'total' => '55'])), $first, 'where the list is');
    assertContains('href="/admin/media?page=2" rel="next"', $first, 'the way to the rest');

    $second = dispatch('/admin/media?page=2')->body;
    assertEquals(5, substr_count($second, 'class="media-row"'), 'rows on the second page');
    assertContains('photo-01', $second, 'the oldest');
    assertContains('href="/admin/media" rel="prev"', $second, 'the way back, to the first page\'s own address');
    assertEquals(5, substr_count(dispatch('/admin/media?page=99')->body, 'class="media-row"'), 'a page past the last shows the last');

    // The search and the filters travel with the page.
    $found = dispatch('/admin/media?q=photo&show=unused')->body;
    assertContains('href="/admin/media?q=photo&amp;show=unused&amp;page=2" rel="next"', $found, 'the search kept');

    // Fifty or fewer: no pager at all.
    $db->query('DELETE FROM media WHERE id IN (' . implode(',', array_slice($ids, 0, 10)) . ')');
    assertTrue(!str_contains(dispatch('/admin/media')->body, 'media-pager'), 'a pager for one page');
});
