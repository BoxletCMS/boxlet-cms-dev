<?php

use App\Core\Session;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\Translations;

// SEVERAL PAGES PUBLISHED AT ONCE (PLAN.md D-254, the owner): "Publish selected" above the pages
// list publishes each ticked page as its row's Publish does, a parent before its subpages; and
// "Copy into language" offers only languages the list is not showing. Helpers from
// pages_admin_test.php (adminSite, adminPost, assertRedirectedTo) and translations_test.php.

testBothDrivers('the ticked drafts are published, a parent first; a live page and a refused one are said', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services', false);
    $design = createPage($db, 'en', 'design', 'Design', false);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$services, $design]);
    $live = createPage($db, 'en', 'about', 'About', true);
    $edited = createPage($db, 'en', 'team', 'Team', true);
    $untitled = createPage($db, 'en', 'news', 'News', false);
    foreach ([$edited => 'Our team', $untitled => ''] as $id => $title) {
        $current = PageDraft::current($db, blockRegistry(), $id) ?? fail('no page');
        $current['document']['title'] = $title;
        PageDraft::save($db, $id, $current['document'], $current['version']);
    }

    $response = adminPost('/admin/pages/publish', ['ids' => [(string) $design, (string) $services, (string) $live, (string) $edited, (string) $untitled, 'x'], 'lang' => 'en']);
    assertRedirectedTo('/admin/pages?lang=en', $response);
    $status = static fn (int $id): ?string => $db->one('SELECT status FROM pages WHERE id = ?', [$id])['status'] ?? null;
    assertEquals(['published', 'published', 'published'], [$status($services), $status($design), $status($edited)], 'the drafts, and the subpage too');
    assertEquals('Our team', $db->one('SELECT title FROM pages WHERE id = ?', [$edited])['title'] ?? null, 'a live page\'s draft is what goes live');
    assertTrue(!PageDraft::exists($db, $edited), 'and the draft is gone');
    assertEquals('draft', $status($untitled), 'a draft the publish refuses stays one');
    assertEquals(
        tn('pages.bulk.published_many', 3) . ' ' . tn('pages.bulk.publish_live_many', 1) . ' '
            . t('pages.bulk.publish_refused', ['title' => 'News', 'problem' => t('pages.title_required')]),
        $_SESSION['flash'] ?? null,
        'how many, which was live already, and which was refused and why',
    );
    assertEquals('warning', $_SESSION['flash_kind'] ?? null, 'a refusal is a warning');
    assertEquals(3, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE action = 'published'")['n'] ?? 0), 'each in the activity');

    adminPost('/admin/pages/publish', []);
    assertEquals(t('pages.bulk.publish_none'), $_SESSION['flash'] ?? null, 'nothing ticked');
});

testBothDrivers('a bulk publish needs a login and the form\'s token', function (string $driver) {
    $db = adminSite($driver);
    $draft = createPage($db, 'en', 'about', 'About', false);
    dispatch('/admin/pages/publish', null, 'POST', ['ids' => [(string) $draft]]);
    unset($_SESSION['admin_id']);
    dispatch('/admin/pages/publish', null, 'POST', ['_csrf' => (new Session())->csrfToken(), 'ids' => [(string) $draft]]);
    assertEquals('draft', $db->one('SELECT status FROM pages WHERE id = ?', [$draft])['status'] ?? null, 'still a draft');
});

testBothDrivers('the bar offers Publish, and copies only into a language the list is not showing', function (string $driver) {
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About', true);
    Translations::create($db, blockRegistry(), $about, 'hr');

    $all = dispatch('/admin/pages')->body;
    assertContains('formaction="/admin/pages/publish" data-bulk-also', $all, 'Publish selected');
    preg_match('~<select id="bulk-copy-to"[^>]*>(.*?)</select>~s', $all, $every);
    assertEquals(['en', 'hr'], preg_match_all('~value="([a-z]+)"~', $every[1] ?? '', $codes) > 0 ? $codes[1] : [], 'every language with none chosen');
    assertContains('value="hr" selected', $every[1] ?? '', 'the second chosen');

    $croatian = dispatch('/admin/pages?lang=hr')->body;
    preg_match('~<select id="bulk-copy-to"[^>]*>(.*?)</select>~s', $croatian, $shown);
    assertEquals(['en'], preg_match_all('~value="([a-z]+)"~', $shown[1] ?? '', $codes) > 0 ? $codes[1] : [], 'pages shown in Croatian are offered English, not Croatian');
});
