<?php

use App\Core\Settings;
use App\Modules\Admin\Search;

// SETTINGS IN TABS (PLAN.md D-294; the owner, 2026-10-24: one long page of anchors was confusing,
// the AI settings in three places). Each tab its own address, marked where it is, showing its
// own panels and no other's; AI one tab of three; Statistics' settings on the Statistics screen;
// an address from the one long page sent on to its tab; Search landing on tabs that are there.
// adminSite() comes from pages_admin_test.php.

/** The tab the screen marks as current, by its address, or how many it marks. */
function currentTab(string $body, string $nav): string
{
    preg_match('~<nav class="' . $nav . '"[^>]*>(.*?)</nav>~s', $body, $tabs);
    preg_match_all('~<a href="([^"]+)" aria-current="page"~', $tabs[1] ?? '', $on);

    return count($on[1]) === 1 ? $on[1][0] : count($on[1]) . ' marked';
}

testBothDrivers('each settings tab is its own page, marked, with its own panels and no other\'s', function (string $driver) {
    adminSite($driver);
    $panels = [
        '/admin/settings' => ['id="general"', 'id="branding"'],
        '/admin/settings/maintenance' => ['id="maintenance"', 'id="cache"'],
        '/admin/settings/languages' => ['id="languages"'],
        '/admin/settings/mail' => ['id="mail"'],
        '/admin/settings/ai' => ['id="ai"'],
        '/admin/settings/ai/access' => ['id="mcp"'],
        '/admin/settings/ai/search' => ['id="llms"'],
        '/admin/settings/account' => ['id="account"', 'id="two-step"'],
    ];
    $every = array_merge(...array_values($panels));
    foreach ($panels as $path => $own) {
        $page = dispatch($path);
        assertEquals(200, $page->status, "{$path}: the page");
        $tab = str_starts_with($path, '/admin/settings/ai') ? '/admin/settings/ai' : $path;
        assertEquals($tab, currentTab($page->body, 'settings-nav'), "{$path}: the tab marked");
        foreach ($every as $id) {
            assertEquals(in_array($id, $own, true), str_contains($page->body, $id), "{$path}: {$id} " . (in_array($id, $own, true) ? 'missing' : 'shown, from another tab'));
        }
        assertTrue(!str_contains($page->body, 'id="statistics"'), "{$path}: Statistics' settings, which moved");
    }
    foreach (['/admin/settings/ai' => '/admin/settings/ai', '/admin/settings/ai/access' => '/admin/settings/ai/access', '/admin/settings/ai/search' => '/admin/settings/ai/search'] as $path => $marked) {
        assertEquals($marked, currentTab(dispatch($path)->body, 'tabs'), "{$path}: the AI tab marked");
    }
    assertEquals(404, dispatch('/admin/settings/nonsense')->status, 'a tab that is none');
    assertEquals(404, dispatch('/admin/settings/ai/nonsense')->status, 'an AI tab that is none');
});

testBothDrivers('Statistics has its settings as a tab, and sends there while counting is off', function (string $driver) {
    $db = adminSite($driver);
    $settings = dispatch('/admin/statistics/settings');
    assertEquals(200, $settings->status, 'the tab');
    assertContains('id="statistics"', $settings->body, 'its panel');
    assertEquals('/admin/statistics/settings', currentTab($settings->body, 'tabs'), 'marked');
    assertEquals('/admin/statistics', currentTab(dispatch('/admin/statistics')->body, 'tabs'), 'and Overview, marked on the counts');
    Settings::set($db, 'stats_enabled', false);
    assertRedirectedTo('/admin/statistics/settings', dispatch('/admin/statistics'));
});

testBothDrivers('an address from the one long page is sent on to its tab, and Search lands on tabs', function (string $driver) {
    $db = adminSite($driver);
    $general = dispatch('/admin/settings')->body;
    preg_match('~data-moved="([^"]+)"~', $general, $attribute);
    $moved = json_decode(html_entity_decode($attribute[1] ?? ''), true) ?? [];
    assertEquals('/admin/settings/mail', $moved['mail'] ?? null, '#mail to Email');
    assertEquals('/admin/settings/ai/access', $moved['mcp'] ?? null, '#mcp to AI access');
    assertEquals('/admin/statistics/settings#geo', $moved['geo'] ?? null, '#geo to the maps on Statistics');
    assertTrue(!isset($moved['branding']) && !isset($moved['timezone']), 'what is still on General stays');
    assertContains('settings-anchors.js', $general, 'with the script that sends it');
    foreach ($moved as $anchor => $href) {
        assertEquals(200, dispatch((string) strtok((string) $href, '#'))->status, "#{$anchor} lands on a page");
    }

    assertTrue(in_array('/admin/settings/ai/access', array_column(Search::find($db, 'mcp'), 'href'), true), 'Search finds AI access');
    assertTrue(in_array('/admin/settings/account', array_column(Search::find($db, 'password'), 'href'), true), 'and the login');
    // Every setting Search knows, each found by its own label: an empty search finds nothing.
    $fixed = new ReflectionMethod(Search::class, 'fixed');
    $settings = array_filter($fixed->invoke(null, $db), static fn (array $entry): bool => $entry['kind'] === 'setting');
    assertTrue(count($settings) >= 14, 'the settings Search knows: ' . count($settings));
    foreach ($settings as $entry) {
        assertEquals(200, dispatch((string) strtok($entry['href'], '#'))->status, "Search's {$entry['label']} lands on a page");
    }
});
