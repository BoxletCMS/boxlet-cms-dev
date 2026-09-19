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
        ['type' => 'columns', 'content' => ['heading' => 'C', 'items' => [['image' => $column, 'heading' => 'One']]]],
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
