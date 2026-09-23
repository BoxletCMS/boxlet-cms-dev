<?php

// Site chrome (PLAN.md D-028, D-030): the rules that keep the header and footer separate
// from page content, and the ones that would rot quietly if only a comment stated them.
//
// The chrome blocks are drawn by the same machinery as page blocks, so they inherit the
// design tokens and the section style layers. Being drawn by it must not mean being OFFERED
// by it: a header the owner can drop into the middle of an article is not a header.

use App\Core\Blocks;
use App\Modules\Menus\MenuTree;
use App\Support\Url;

/** The chrome registry: the same machine over a different directory, which is the whole design. */
function chromeRegistry(): Blocks
{
    static $registry = null;

    return $registry ??= Blocks::discover(dirname(__DIR__) . '/app/Chrome');
}

test('the page library does not offer the header or the footer', function (): void {
    $pages = blockRegistry();
    assertTrue(!$pages->has('header'), 'the page block library offers a header');
    assertTrue(!$pages->has('footer'), 'the page block library offers a footer');
    // The two sets share nothing, said as disjointness rather than as a second copy of the
    // page set: blocks_test owns that list, and a list written twice is a list that goes
    // stale in the copy nobody is looking at (it did, when D-105 added blocks).
    assertEquals([], array_intersect($pages->types(), chromeRegistry()->types()), 'types in both registries');
});

test('the chrome registry offers exactly the header and the footer', function (): void {
    assertEquals(['footer', 'header'], chromeRegistry()->types(), 'chrome block types');
});

// The menu is NOT a field. No field type points at one, none was added, and FIELD_TYPES is
// frozen (SPEC §5.3): the chrome screen names the menu and the renderer hands it in.
test('neither chrome block stores a menu of its own', function (): void {
    foreach (['header', 'footer'] as $type) {
        $fields = array_keys(chromeRegistry()->get($type)['fields']);
        assertTrue(!in_array('menu', $fields, true), "{$type} declares a menu field: " . implode(', ', $fields));
    }
});

/*
 * A PAGE BLOCK IS RENDERED WITHOUT $resolved.
 *
 * Asserted on the signature rather than on a rendered page, because a page template does
 * not read $resolved and would pass this check while the renderer handed it everything.
 * What matters is the contract: the argument exists, and a caller gets nothing in it unless
 * it deliberately passes something. If a second kind of value is ever added, this is where
 * the argument happens (D-030).
 */
test('render hands nothing in unless a caller asks it to', function (): void {
    $parameters = [];
    foreach ((new ReflectionMethod(Blocks::class, 'render'))->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter;
    }

    foreach (['resolved' => [], 'locales' => [], 'locale' => '', 'wrapper' => 'section'] as $name => $expected) {
        assertTrue(isset($parameters[$name]), "render() has no {$name} parameter");
        assertTrue($parameters[$name]->isDefaultValueAvailable(), "render()'s {$name} has no default");
        assertEquals($expected, $parameters[$name]->getDefaultValue(), "render()'s {$name} default");
    }
});

/*
 * ONE PLACE COMPOSES A CHROME SETTINGS KEY.
 *
 * `settings` has no locale column, so a language-specific value carries its locale in the
 * key. That shape lives in SiteChrome::key() and nowhere else — Settings itself exists
 * because six places had grown their own copy of one contract, and a suffix spelled out at
 * each call site would be exactly that again.
 *
 * A scan rather than a comment, for the same reason run.mjs scans for the triple click: a
 * rule that lives only in prose is a rule that comes back.
 */
test('only SiteChrome composes a chrome settings key', function (): void {
    $offenders = [];
    $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app'));
    foreach ($directory as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = (string) $file->getPathname();
        if (basename($path) === 'SiteChrome.php') {
            continue;
        }
        if (str_contains((string) file_get_contents($path), "'chrome_")) {
            $offenders[] = basename($path);
        }
    }

    assertEquals([], $offenders, 'files outside SiteChrome naming a chrome_ settings key');
});

/*
 * A MENU THAT IS DELETED LEAVES NO HOLE.
 *
 * The chrome stores a menu's NAME, not its id, so nothing dangles when a menu is deleted
 * and nothing has to be cleaned up when it is made again under the same name. What must
 * never happen is an empty <nav>, or markup half-built around something that is not there.
 *
 * Both sides are asserted. A negative on its own — "no nav when the menu is gone" — passes
 * just as happily when the menu never renders at all, which is the shape of check that
 * hides a broken feature rather than catching one.
 */
testBothDrivers('chrome renders with its menu, and without it once the menu is gone', function (string $driver): void {
    $db = installedSite(['en' => 'English'], $driver);
    Url::configure('', 'en', 'http://localhost');

    $now = gmdate('Y-m-d H:i:s');
    $db->query('INSERT INTO pages (locale, slug, title, status, sort, translation_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)', ['en', 'about', 'About', 'published', 'source', $now, $now]);
    $pageId = (int) $db->lastInsertId();

    $db->query('INSERT INTO menus (locale, name, created_at, updated_at) VALUES (?, ?, ?, ?)', ['en', 'Chrome', $now, $now]);
    $menuId = (int) $db->lastInsertId();
    $db->query('INSERT INTO menu_items (menu_id, page_id, label, sort, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)',
        [$menuId, $pageId, 'About', $now, $now]);

    $withMenu = chromeRegistry()->render(
        'header', [], [], 'left', [], true, 'header',
        ['menu' => MenuTree::forVisitors($db, 'en', 'Chrome')], 'en', [],
    );
    assertContains('site-nav', $withMenu, 'the header with a menu');
    assertContains('About', $withMenu, 'the header with a menu');

    // The owner deletes the menu. The chrome still names "Chrome"; nothing points at an id.
    $db->query('DELETE FROM menus WHERE id = ?', [$menuId]);

    $resolved = MenuTree::forVisitors($db, 'en', 'Chrome');
    assertEquals([], $resolved, 'a deleted menu resolves to nothing');

    $without = chromeRegistry()->render(
        'header', [], [], 'left', [], true, 'header', ['menu' => $resolved], 'en', [],
    );
    assertTrue(!str_contains($without, 'site-nav'), 'the header drew an empty nav for a deleted menu');
    assertContains('<header class="block block-header', $without, 'the header itself');
});
