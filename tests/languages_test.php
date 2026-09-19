<?php

use App\Modules\Languages\Locales;

// The Languages panel on the Settings screen (PLAN.md D-043). adminSite() gives English as
// the main language and Croatian beside it; adminPost() and assertRedirectedTo() come from
// pages_admin_test.php. Asserted on what a visitor is served wherever it can be, because a
// language switched off in the table and still answering at its address is no switch.

/** The flash message a redirect left, read off the screen it leads to. */
function languagesScreen(): string
{
    return dispatch('/admin/settings')->body;
}

testBothDrivers('the panel lists the languages, the main one first and marked, and offers the rest', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'hr', 'o-nama', 'O nama');
    $body = languagesScreen();
    assertContains('id="languages"', $body, 'the panel');
    // Read inside the panel: other controls on the screen name languages too.
    $body = substr($body, (int) strpos($body, 'id="languages"'));

    preg_match_all('~class="language-name"[^>]*>\s*([^<\s]+)~', $body, $names);
    assertEquals(['English', 'Hrvatski'], $names[1], 'the languages, in order');
    assertContains(e(t('languages.primary')), $body, 'the main language is not marked');
    // Offered: a language not on the site. Not offered: one that is.
    assertContains('<option value="de">Deutsch (de)</option>', $body, 'German is not offered');
    assertTrue(!str_contains($body, '<option value="hr">'), 'a language already on the site was offered again');
    // Croatian holds a page, so it can be switched off but not removed.
    assertTrue(!str_contains($body, 'languages/hr/delete'), 'a language holding a page offers removal');
});

testBothDrivers('an added language is on, last, falls back to the main one, and serves its pages', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/settings#languages', adminPost('/admin/languages', ['code' => 'de']));
    $row = $db->one('SELECT label, enabled, fallback, sort FROM locales WHERE code = ?', ['de']);
    assertEquals('Deutsch', $row['label'] ?? null, 'its name, in its own language');
    assertEquals(1, (int) ($row['enabled'] ?? 0), 'switched on');
    assertEquals('en', $row['fallback'] ?? null, 'falls back to the main language (D-043)');
    assertEquals('de', array_column(Locales::all($db), 'code')[2] ?? null, 'placed last');
    assertContains(e(t('languages.added', ['language' => 'Deutsch'])), languagesScreen(), 'the confirmation');

    createPage($db, 'de', 'ueber-uns', 'Über uns');
    assertEquals(200, dispatch('/de/ueber-uns')->status, 'its page is not served');
});

testBothDrivers('a language that does not exist, or is already here, is refused and says so', function (string $driver) {
    $db = adminSite($driver);

    adminPost('/admin/languages', ['code' => 'zz']);
    assertContains(e(t('languages.unknown')), languagesScreen(), 'an unknown code');
    adminPost('/admin/languages', ['code' => 'hr']);
    assertContains(e(t('languages.exists', ['language' => 'Hrvatski'])), languagesScreen(), 'a language already here');
    assertEquals(2, count(Locales::all($db)), 'languages after two refusals');
});

testBothDrivers('a language switched off stops being served, and the main one cannot be switched off', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'hr', 'o-nama', 'O nama');
    assertEquals(200, dispatch('/hr/o-nama')->status, 'served while on');

    assertRedirectedTo('/admin/settings#languages', adminPost('/admin/languages/hr/enabled', ['enabled' => '0']));
    assertEquals(404, dispatch('/hr/o-nama')->status, 'still served once switched off');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages WHERE locale = ?', ['hr'])['n'] ?? 0), 'its page was not kept');

    adminPost('/admin/languages/en/enabled', ['enabled' => '0']);
    assertEquals(1, (int) ($db->one('SELECT enabled FROM locales WHERE code = ?', ['en'])['enabled'] ?? 0), 'the main language was switched off');
    assertContains(e(t('languages.primary_fixed')), languagesScreen(), 'the refusal');
});

testBothDrivers('languages move among themselves, never above the main one', function (string $driver) {
    $db = adminSite($driver);
    Locales::add($db, 'de');
    Locales::add($db, 'it');

    adminPost('/admin/languages/it/move', ['move' => 'up']);
    assertEquals(['en', 'hr', 'it', 'de'], array_column(Locales::all($db), 'code'), 'after moving Italian up');
    adminPost('/admin/languages/hr/move', ['move' => 'up']);
    assertEquals(['en', 'hr', 'it', 'de'], array_column(Locales::all($db), 'code'), 'the first after the main one moved above it');
    adminPost('/admin/languages/hr/move', ['move' => 'down']);
    assertEquals(['en', 'it', 'hr', 'de'], array_column(Locales::all($db), 'code'), 'after moving Croatian down');
});

testBothDrivers('only a language nothing is written in can be removed', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'hr', 'o-nama', 'O nama');
    Locales::add($db, 'de');
    $picture = storedPicture($db, 'harbour', []);
    $db->query('INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)', [$picture, 'de', 'Hafen', '']);

    adminPost('/admin/languages/hr/delete', []);
    assertTrue($db->one('SELECT code FROM locales WHERE code = ?', ['hr']) !== null, 'a language holding a page was removed');
    assertContains('cannot be removed while it holds 1 pages', html_entity_decode(languagesScreen()), 'the refusal names what it holds');

    assertRedirectedTo('/admin/settings#languages', adminPost('/admin/languages/de/delete', []));
    assertEquals(null, $db->one('SELECT code FROM locales WHERE code = ?', ['de']), 'an empty language was kept');
    assertEquals(null, $db->one('SELECT alt FROM media_meta WHERE locale = ?', ['de']), 'its alt texts were left behind');

    adminPost('/admin/languages/en/delete', []);
    assertTrue($db->one('SELECT code FROM locales WHERE code = ?', ['en']) !== null, 'the main language was removed');
});
