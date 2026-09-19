<?php

use App\Modules\Pages\Translations;

// Translating a page (PLAN.md D-043, step 2). adminSite() gives English as the main
// language and Croatian beside it; adminPost() and assertRedirectedTo() come from
// pages_admin_test.php.

/** An English page with a hero and a text block, published, and its id. */
function sourcePage(App\Core\Db $db, string $slug = 'about', string $title = 'About us'): int
{
    return createPage($db, 'en', $slug, $title, true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'cta' => ['label' => 'Contact', 'url' => '/contact']]],
        ['type' => 'text', 'content' => ['heading' => 'Words', 'body' => '<p>Some words.</p>'], 'layout' => 'columns'],
    ]);
}

testBothDrivers('a translation is a draft copy in the same group, each block tied to its source', function (string $driver) {
    $db = adminSite($driver);
    $source = sourcePage($db);

    $id = Translations::create($db, blockRegistry(), $source, 'hr');
    assertTrue(is_int($id), 'no translation: ' . var_export($id, true));
    $page = $db->one('SELECT * FROM pages WHERE id = ?', [$id]);
    assertEquals('hr', $page['locale'] ?? null, 'its language');
    assertEquals('draft', $page['status'] ?? null, 'a translation is not published before it is translated');
    assertEquals($source, (int) ($page['content_group_id'] ?? 0), 'its group');
    assertEquals('about', $page['slug'] ?? null, 'the source\'s address, free in this language');
    assertEquals('About us', $page['title'] ?? null, 'the title, to be translated');

    $from = $db->all('SELECT block_group_id, block_type, content_json, style_json, layout FROM page_blocks WHERE page_id = ? ORDER BY sort', [$source]);
    $to = $db->all('SELECT block_group_id, block_type, content_json, style_json, layout, translation_status, source_hash FROM page_blocks WHERE page_id = ? ORDER BY sort', [$id]);
    assertEquals(count($from), count($to), 'blocks copied');
    foreach ($from as $i => $block) {
        assertEquals((int) $block['block_group_id'], (int) $to[$i]['block_group_id'], "block {$i}: not tied to its source block");
        assertEquals($block['content_json'], $to[$i]['content_json'], "block {$i}: content");
        assertEquals($block['layout'], $to[$i]['layout'], "block {$i}: layout");
        $content = json_decode((string) $block['content_json'], true);
        assertEquals(Translations::blockHash(blockRegistry(), (string) $block['block_type'], $content), $to[$i]['source_hash'], "block {$i}: what it was translated from");
    }
});

testBothDrivers('asking again opens the translation that exists, and a translation is made from the source', function (string $driver) {
    $db = adminSite($driver);
    App\Modules\Languages\Locales::add($db, 'de');
    $source = sourcePage($db);
    $croatian = Translations::create($db, blockRegistry(), $source, 'hr');

    assertEquals($croatian, Translations::create($db, blockRegistry(), $source, 'hr'), 'a second copy');
    // Asked from the Croatian page: the German one still starts from the English source.
    $db->query('UPDATE pages SET title = ? WHERE id = ?', ['O nama', $croatian]);
    $german = Translations::create($db, blockRegistry(), (int) $croatian, 'de');
    assertEquals('About us', $db->one('SELECT title FROM pages WHERE id = ?', [$german])['title'] ?? null, 'copied from the translation, not the source');
    assertEquals(['en' => $source, 'hr' => $croatian, 'de' => $german], Translations::of($db, (int) $german), 'the group');
});

testBothDrivers('a taken address gets a free one, and a parent is the parent\'s translation where there is one', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'hr', 'about', 'Something else');
    $source = sourcePage($db);
    $id = Translations::create($db, blockRegistry(), $source, 'hr');
    assertEquals('about-us', $db->one('SELECT slug FROM pages WHERE id = ?', [$id])['slug'] ?? null, 'the address in Croatian');

    $child = createPage($db, 'en', 'team', 'Team');
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$source, $child]);
    $team = Translations::create($db, blockRegistry(), $child, 'hr');
    assertEquals($id, (int) ($db->one('SELECT parent_id FROM pages WHERE id = ?', [$team])['parent_id'] ?? 0), 'the translated parent');

    $orphan = createPage($db, 'en', 'jobs', 'Jobs');
    $untranslated = createPage($db, 'en', 'company', 'Company');
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$untranslated, $orphan]);
    $jobs = Translations::create($db, blockRegistry(), $orphan, 'hr');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$jobs])['parent_id'] ?? null, 'a parent in another language');
});

testBothDrivers('a language that is off or unknown is refused', function (string $driver) {
    $db = adminSite($driver);
    $source = sourcePage($db);
    $db->query('UPDATE locales SET enabled = 0 WHERE code = ?', ['hr']);

    assertEquals(t('translations.unknown_language'), Translations::create($db, blockRegistry(), $source, 'hr'), 'a language switched off');
    assertEquals(t('translations.unknown_language'), Translations::create($db, blockRegistry(), $source, 'xx'), 'a language that does not exist');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0), 'pages after two refusals');
});

test('what a translation was made from is its words, not its pictures', function () {
    $registry = blockRegistry();
    $hero = ['heading' => 'Hello', 'subheading' => '', 'image' => null, 'cta' => ['label' => 'Go', 'url' => '/x']];
    $hash = Translations::blockHash($registry, 'hero', $hero);

    assertEquals($hash, Translations::blockHash($registry, 'hero', ['image' => 12] + $hero), 'a picture changed the hash');
    assertTrue($hash !== Translations::blockHash($registry, 'hero', ['heading' => 'Hi'] + $hero), 'a heading did not');

    $columns = ['items' => [['heading' => 'One', 'body' => '', 'image' => null, 'link' => ['label' => '', 'url' => '']]]];
    $before = Translations::blockHash($registry, 'columns', $columns);
    $columns['items'][0]['heading'] = 'Uno';
    assertTrue($before !== Translations::blockHash($registry, 'columns', $columns), 'a word inside a column did not');
});

testBothDrivers('the builder offers the other language, and the menu makes and then opens its version', function (string $driver) {
    $db = adminSite($driver);
    $source = sourcePage($db);

    $body = dispatch("/admin/pages/{$source}")->body;
    assertContains('form="translate-hr"', $body, 'no way to translate into Croatian');
    assertContains('id="translate-hr"', $body, 'the form it posts');

    $response = adminPost("/admin/pages/{$source}/translate", ['locale' => 'hr']);
    $id = (int) ($db->one("SELECT id FROM pages WHERE locale = 'hr'")['id'] ?? 0);
    assertRedirectedTo("/admin/pages/{$id}", $response);
    $translation = dispatch("/admin/pages/{$id}")->body;
    assertContains(e(t('translations.created', ['language' => 'Hrvatski'])), $translation, 'what happened is said');
    assertContains('href="/admin/pages/' . $source . '"', $translation, 'the English version is not offered from the Croatian one');
    assertTrue(!str_contains(dispatch("/admin/pages/{$source}")->body, 'form="translate-hr"'), 'Croatian is still offered as missing');
});

test('with one language the builder names it and offers nothing', function () {
    $db = installedSite(['en' => 'English']);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    $page = createPage($db, 'en', 'about', 'About');

    $body = dispatch("/admin/pages/{$page}")->body;
    assertTrue(!str_contains($body, 'translate-'), 'a translate control on a one-language site');
    assertContains('<span class="builder-locale"', $body, 'the language, named');
});

