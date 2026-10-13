<?php

use App\Core\Session;
use App\Modules\Pages\Translations;

// A PAGE INTO ANOTHER LANGUAGE FROM THE PAGES LIST (PLAN.md D-247, the owner): each row's menu
// opens its version in the site's other languages or makes it, and "Copy into language" makes
// the ticked pages' versions at once, parents before their subpages. Helpers from
// pages_admin_test.php (adminSite, adminPost, assertRedirectedTo).

testBothDrivers('a row\'s menu opens a page\'s version in another language, or makes it', function (string $driver) {
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About', true);
    $team = createPage($db, 'en', 'team', 'Team', true);
    $croatian = Translations::create($db, blockRegistry(), $team, 'hr');

    $list = dispatch('/admin/pages')->body;
    assertContains('<select id="bulk-copy-to" name="to"', $list, 'the bar offers a language to copy into');
    assertContains('formaction="/admin/pages/copy" data-bulk-also', $list, 'and its button');
    assertContains('action="/admin/pages/' . $about . '/translate"', $list, 'About has no Croatian version: Translate');
    assertContains(e(t('pages.translate_into', ['language' => 'Hrvatski'])), $list, 'said with the language');
    assertContains('href="/admin/pages/' . $croatian . '"><svg', $list, 'Team has one: Open');
    assertContains(e(t('pages.open_in', ['language' => 'Hrvatski'])), $list, 'said with the language');
    assertContains('href="/admin/pages/' . $team . '"><svg', $list, 'and the Croatian row opens the English');

    $response = adminPost('/admin/pages/' . $about . '/translate', ['locale' => 'hr']);
    $made = Translations::of($db, $about)['hr'] ?? null;
    assertTrue(is_int($made), 'made from the row');
    assertRedirectedTo('/admin/pages/' . $made, $response);
});

testBothDrivers('the ticked pages are copied into a language, each under its parent\'s copy', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services', true);
    $design = createPage($db, 'en', 'design', 'Design', true);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$services, $design]);
    $already = createPage($db, 'en', 'contact', 'Contact', true);
    Translations::create($db, blockRegistry(), $already, 'hr');
    $croatianPage = createPage($db, 'hr', 'vijesti', 'Vijesti', true);

    // The subpage ticked first: its parent is still copied before it.
    $response = adminPost('/admin/pages/copy', ['ids' => [(string) $design, (string) $services, (string) $already, (string) $croatianPage, 'x'], 'to' => 'hr', 'lang' => 'en']);
    assertRedirectedTo('/admin/pages?lang=en', $response);

    $servicesHr = Translations::of($db, $services)['hr'] ?? null;
    $designHr = Translations::of($db, $design)['hr'] ?? null;
    assertTrue(is_int($servicesHr) && is_int($designHr), 'both copied');
    $row = $db->one('SELECT status, parent_id FROM pages WHERE id = ?', [$designHr]) ?? [];
    assertEquals($servicesHr, (int) ($row['parent_id'] ?? 0), 'the subpage under its parent\'s Croatian version');
    assertEquals('draft', $row['status'] ?? null, 'a draft, to be translated');
    assertEquals(
        tn('pages.bulk.copied_many', 2, ['language' => 'Hrvatski']) . ' ' . tn('pages.bulk.copy_passed_many', 2, ['language' => 'Hrvatski']),
        $_SESSION['flash'] ?? null,
        'how many were made, and how many were passed over',
    );
    assertEquals(2, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE action = 'translated'")['n'] ?? 0), 'each in the activity');
    assertEquals(1, count(Translations::of($db, $already)) - 1, 'the one that had a version has still one');
});

testBothDrivers('a copy into no language, or of nothing, says so and makes nothing', function (string $driver) {
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About', true);

    adminPost('/admin/pages/copy', ['ids' => [(string) $about], 'to' => 'xx']);
    assertEquals(t('translations.unknown_language'), $_SESSION['flash'] ?? null, 'a language the site does not have');
    adminPost('/admin/pages/copy', ['to' => 'hr']);
    assertEquals(t('pages.bulk.copy_none'), $_SESSION['flash'] ?? null, 'nothing ticked');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0), 'no page made');

    unset($_SESSION['admin_id']);
    $response = dispatch('/admin/pages/copy', null, 'POST', ['_csrf' => (new Session())->csrfToken(), 'ids' => [(string) $about], 'to' => 'hr']);
    assertEquals(302, $response->status, 'sent to log in');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0), 'and nothing made without a login');
});

testBothDrivers('a site in one language offers no copy at all', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    createPage($db, 'en', 'about', 'About', true);

    $list = dispatch('/admin/pages')->body;
    assertTrue(!str_contains($list, 'id="bulk-copy-to"'), 'no language to choose');
    assertTrue(!str_contains($list, '/translate"'), 'no row offers a translation');
    assertContains('formaction="/admin/pages/publish"', $list, 'Publish selected is there all the same');
});
