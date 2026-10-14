<?php

use App\Modules\Ai\AiQueue;
use App\Modules\Forms\Form;
use App\Modules\Menus\Menu;
use App\Modules\Menus\MenuCopy;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\Translations;
use App\Modules\Settings\ChromeWords;
use App\Modules\Settings\SiteChrome;

// WHAT A PAGE BRINGS INTO ANOTHER LANGUAGE BESIDE ITSELF (PLAN.md D-262, the owner): its place
// in that language's menus, mirrored from the original's, and its forms in that language; and
// with AI, the menus' own labels, the header and footer words and the forms' words, translated.
// Helpers from pages_admin_test.php (adminSite, adminPost) and ai_test.php (aiSite, aiEcho,
// aiRunAll).

/**
 * The menu of $name in $locale, as rows: [label, where, parent's where], in order.
 *
 * @return list<array{0: string|null, 1: string, 2: string|null}>
 */
function menuShape(App\Core\Db $db, string $locale, string $name): array
{
    $menu = $db->one('SELECT id FROM menus WHERE locale = ? AND name = ?', [$locale, $name]);
    if ($menu === null) {
        return [];
    }
    $where = static fn (array $row): string => $row['page_id'] !== null ? 'page:' . (string) ($db->one('SELECT slug FROM pages WHERE id = ?', [(int) $row['page_id']])['slug'] ?? '?') : 'url:' . (string) $row['url'];
    $rows = $db->all('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END, sort, id', [(int) $menu['id']]);
    $byId = array_column($rows, null, 'id');
    $shape = [];
    foreach ($rows as $row) {
        $parent = $row['parent_id'] === null ? null : $where($byId[$row['parent_id']]);
        $shape[] = [$row['label'] === null ? null : (string) $row['label'], $where($row), $parent];
    }

    return $shape;
}

/**
 * An English site with a main menu: Home, Services (named "What we do") with Design under it, an
 * About typed as an address, and a shop.
 *
 * @return array{0: App\Core\Db, 1: array{home: int, services: int, design: int, about: int}}
 */
function menuSite(string $driver): array
{
    $db = aiSite($driver);
    $home = createPage($db, 'en', '', 'Home', true);
    $services = createPage($db, 'en', 'services', 'Services', true);
    $design = createPage($db, 'en', 'design', 'Design', true);
    $about = createPage($db, 'en', 'about', 'About', true);
    $main = Menu::create($db, 'en', 'Main');
    Menu::addItem($db, $main, null, $home, null, null);
    $servicesItem = (int) Menu::addItem($db, $main, null, $services, null, 'What we do');
    Menu::addItem($db, $main, $servicesItem, $design, null, null);
    Menu::addItem($db, $main, null, null, '/about', 'About us');
    Menu::addItem($db, $main, null, null, 'https://shop.example.com/', 'Shop');

    return [$db, ['home' => $home, 'services' => $services, 'design' => $design, 'about' => $about]];
}

testBothDrivers('pages copied into a language take their places in its menu, in the original\'s shape', function (string $driver) {
    [$db, $pages] = menuSite($driver);

    // The subpage ticked first: pages are copied parents first.
    adminPost('/admin/pages/copy', ['ids' => [(string) $pages['design'], (string) $pages['services'], (string) $pages['home']], 'to' => 'hr']);
    assertEquals([
        [null, 'page:', null],
        [null, 'page:services', null],
        ['Shop', 'url:https://shop.example.com/', null],
        [null, 'page:design', 'page:services'],
    ], menuShape($db, 'hr', 'Main'), 'Home, Services, the shop; Design under Services; About waits for its page');

    $hrServices = Translations::of($db, $pages['services'])['hr'];
    $item = $db->one('SELECT label FROM menu_items WHERE page_id = ?', [$hrServices]);
    assertTrue($item !== null && array_key_exists('label', $item), 'the Croatian Services has an item');
    assertEquals(null, $item['label'] ?? null, 'a page\'s item has no label: it shows the Croatian page\'s title');

    // The address typed for About becomes About's Croatian page when it has one.
    adminPost('/admin/pages/copy', ['ids' => [(string) $pages['about']], 'to' => 'hr']);
    $shape = menuShape($db, 'hr', 'Main');
    assertEquals([null, 'page:about', null], $shape[2] ?? null, 'in its place, after Services');
    assertEquals(5, count($shape), 'nothing doubled');
    assertEquals(0, MenuCopy::sync($db, 'en', 'hr'), 'and nothing left to add');
});

testBothDrivers('a menu the owner arranged is added to, never moved or doubled; Match adds what came later', function (string $driver) {
    [$db, $pages] = menuSite($driver);
    $hrHome = (int) Translations::create($db, blockRegistry(), $pages['home'], 'hr');
    $own = Menu::create($db, 'hr', 'Main');
    Menu::addItem($db, $own, null, null, 'https://blog.example.com/', 'Blog');
    Menu::addItem($db, $own, null, $hrHome, null, 'Naslovnica');

    adminPost('/admin/pages/copy', ['ids' => [(string) $pages['services']], 'to' => 'hr']);
    $shape = menuShape($db, 'hr', 'Main');
    assertEquals(['Blog', 'url:https://blog.example.com/', null], $shape[0] ?? null, 'the owner\'s first item where it was');
    assertEquals(1, count(array_filter($shape, static fn (array $row): bool => $row[1] === 'page:')), 'their Home adopted, not doubled');
    assertEquals('Naslovnica', $shape[1][0] ?? null, 'with their label');

    // The English menu gains an item later: Match brings it.
    $hrDesign = (int) Translations::create($db, blockRegistry(), $pages['design'], 'hr');
    $main = (int) ($db->one("SELECT id FROM menus WHERE locale = 'en' AND name = 'Main'")['id'] ?? 0);
    Menu::addItem($db, $main, null, null, 'https://press.example.com/', 'Press');
    adminPost('/admin/navigation/match', ['locale' => 'hr']);
    assertEquals(tn('menus.matched', 2, ['language' => 'Hrvatski']), $_SESSION['flash'] ?? null, 'Design under Services, and Press');
    $shape = menuShape($db, 'hr', 'Main');
    assertTrue(in_array([null, 'page:design', 'page:services'], $shape, true), 'Design under Services');
    assertTrue(in_array(['Press', 'url:https://press.example.com/', null], $shape, true), 'Press');
    assertContains('/admin/navigation/match', dispatch('/admin/navigation')->body, 'the button on Navigation');
    adminPost('/admin/navigation/match', ['locale' => 'en']);
    assertEquals(t('translations.unknown_language'), $_SESSION['flash'] ?? null, 'the main language is not matched with itself');
});

testBothDrivers('a copied page\'s form is its language\'s: one copy, shared, the original untouched', function (string $driver) {
    $db = aiSite($driver);
    $form = Form::create($db, 'en', 'Contact');
    $contact = createPage($db, 'en', 'contact', 'Contact', true, [['type' => 'form', 'content' => ['heading' => 'Write to us', 'form' => $form]]]);
    $visit = createPage($db, 'en', 'visit', 'Visit', false, [['type' => 'form', 'content' => ['heading' => 'Ask', 'form' => $form]]]);

    adminPost('/admin/pages/copy', ['ids' => [(string) $contact, (string) $visit], 'to' => 'hr']);
    $copies = $db->all("SELECT id, name, source_form_id, fields_json FROM forms WHERE locale = 'hr'");
    assertEquals(1, count($copies), 'one Croatian form for both pages');
    $hrForm = (int) ($copies[0]['id'] ?? 0);
    assertEquals([$form, 'Contact'], [(int) ($copies[0]['source_form_id'] ?? 0), $copies[0]['name'] ?? null], 'remembering its original, under its name');

    $formIn = static function (int $pageId) use ($db): ?int {
        $block = $db->one("SELECT content_json FROM page_blocks WHERE page_id = ? AND block_type = 'form'", [$pageId]);

        return json_decode((string) ($block['content_json'] ?? '{}'), true)['form'] ?? null;
    };
    assertEquals($hrForm, $formIn((int) Translations::of($db, $contact)['hr']), 'the Croatian Contact shows it');
    $visitDraft = PageDraft::current($db, blockRegistry(), (int) Translations::of($db, $visit)['hr'])['document']['blocks'][0]['content']['form'] ?? null;
    assertEquals($hrForm, $visitDraft, 'and the draft of a page never published, which is what it is');
    assertEquals($form, $formIn($contact), 'the English page keeps the English form');
    adminPost('/admin/pages/publish', ['ids' => [(string) Translations::of($db, $contact)['hr']]]);
    assertContains('action="/form/' . $hrForm, dispatch('/hr/contact')->body, 'drawn on the Croatian page, published');
});

testBothDrivers('with AI, the menu\'s own labels, the header and footer words and a copied form are translated; the owner\'s words are not', function (string $driver) {
    [$db, $pages] = menuSite($driver);
    SiteChrome::saveForLocale($db, 'en', ['button_label' => 'Book', 'button_url' => 'page:' . $pages['about'], 'columns' => [['title' => 'Visit', 'text' => '<p>Open daily</p>'], ['title' => '', 'text' => ''], ['title' => '', 'text' => '']], 'small_print' => 'All rights']);
    SiteChrome::saveForLocale($db, 'hr', ['button_label' => '', 'button_url' => '', 'columns' => [['title' => 'Posjet', 'text' => ''], ['title' => '', 'text' => ''], ['title' => '', 'text' => '']], 'small_print' => '']);
    $form = Form::create($db, 'en', 'Contact');
    $contact = createPage($db, 'en', 'contact', 'Contact', true, [['type' => 'form', 'content' => ['heading' => 'Write', 'form' => $form]]]);

    adminPost('/admin/pages/copy', ['ids' => [(string) $pages['home'], (string) $pages['services'], (string) $contact], 'to' => 'hr', 'ai' => '1']);
    $parts = array_column($db->all('SELECT part FROM ai_queue ORDER BY id'), 'part');
    assertEquals(1, count(array_keys($parts, 'navigation', true)), 'the language\'s menus and words, once');
    $hrForm = (int) ($db->one("SELECT id FROM forms WHERE locale = 'hr'")['id'] ?? 0);
    assertTrue(in_array('form:' . $hrForm, $parts, true), 'and the Croatian form');
    aiRunAll($db, aiEcho());

    $shape = menuShape($db, 'hr', 'Main');
    // Its address is Croatian too: a page never published takes one in its language (D-253).
    assertEquals(['HR What we do', 'page:hr-services', null], $shape[1] ?? null, 'a label of the original\'s own, translated');
    assertEquals([null, 'page:', null], $shape[0] ?? null, 'Home has none: it shows its page\'s title');
    assertEquals(['HR Shop', 'url:https://shop.example.com/', null], $shape[2] ?? null, 'an address\'s label, translated');
    $words = ChromeWords::stored($db, ['hr'])['hr'];
    assertEquals(['HR Book', 'page:' . $pages['about'], 'Posjet', 'HR All rights'], [$words['button_label'], $words['button_url'], $words['title'], $words['small_print']], 'empty words translated, the button\'s page with its label, the owner\'s title kept');
    assertContains('HR Open daily', $words['text'], 'the footer\'s rich text too');
    $fields = Form::find($db, $hrForm)['fields'] ?? [];
    assertTrue($fields !== [] && str_starts_with($fields[0]['label'], 'HR '), 'the form\'s labels translated');
    assertEquals('Contact', Form::find($db, $hrForm)['name'] ?? null, 'not its name, which only the owner sees');

    // Translated again: nothing of the menus, the words or the form is asked for again.
    $db->query("UPDATE menu_items SET label = 'Usluge' WHERE label = 'HR What we do'");
    AiQueue::page($db, blockRegistry(), (int) Translations::of($db, $pages['services'])['hr']);
    $again = array_column($db->all("SELECT part FROM ai_queue WHERE state = 'pending'"), 'part');
    assertTrue(!in_array('navigation', $again, true) && !in_array('form:' . $hrForm, $again, true), 'nothing left: ' . implode(', ', $again));
});
