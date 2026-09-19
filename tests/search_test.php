<?php

use App\Modules\Admin\Search;

// Search across the admin (PLAN.md D-052). adminSite() comes from pages_admin_test.php and
// createPage() from fixtures.php.

/** @return list<string> "kind: label" for each result */
function searched(App\Core\Db $db, string $query): array
{
    return array_map(static fn (array $r): string => $r['kind'] . ': ' . $r['label'], Search::find($db, $query));
}

testBothDrivers('a setting is found by its name and lands on its field', function (string $driver) {
    $db = adminSite($driver);

    $results = Search::find($db, 'time zone');
    assertEquals('setting: ' . t('settings.timezone'), ($results[0]['kind'] ?? '') . ': ' . ($results[0]['label'] ?? ''), 'the first result');
    assertEquals('/admin/settings#timezone', $results[0]['href'] ?? null, 'where it goes');
});

testBothDrivers('pages and screens are found, screens first, a name that starts with the words before one that contains them', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About us');
    createPage($db, 'en', 'team', 'Meet us about things');

    $found = searched($db, 'about');
    assertEquals(['page: About us', 'page: Meet us about things'], array_values(array_filter($found, static fn (string $f): bool => str_starts_with($f, 'page:'))), 'pages, in rank');
    assertEquals('go: ' . t('admin.nav.media'), searched($db, 'pictures')[0] ?? null, 'a screen by a word it is known by');
    assertTrue(in_array('action: ' . t('pages.new'), searched($db, 'new page'), true), 'an action');
});

testBothDrivers('the words are words: a % or an _ finds only itself', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About us');
    createPage($db, 'en', 'sale', '50% off');

    assertEquals(['page: 50% off'], array_values(array_filter(searched($db, '%'), static fn (string $f): bool => str_starts_with($f, 'page:'))), 'a percent sign');
    assertEquals([], array_values(array_filter(searched($db, '_'), static fn (string $f): bool => str_starts_with($f, 'page:'))), 'an underscore');
});

testBothDrivers('the Search screen and the palette\'s fragment show the same results', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About us');

    $screen = dispatch('/admin/search?q=about')->body;
    $fragment = dispatch('/admin/search?q=about&fragment=1')->body;
    assertContains('About us', $screen, 'on the screen');
    assertContains('About us', $fragment, 'in the fragment');
    assertTrue(!str_contains($fragment, '<html'), 'the fragment carries the whole page');
    assertContains(e(t('search.none', ['query' => 'zzz'])), dispatch('/admin/search?q=zzz&fragment=1')->body, 'nothing found, said');
    assertContains('data-palette-open', dispatch('/admin')->body, 'the rail\'s Search');
});
