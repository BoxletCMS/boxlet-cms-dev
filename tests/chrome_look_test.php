<?php

use App\Core\Settings;
use App\Modules\Design\Composition;
use App\Modules\Menus\Menu;
use App\Modules\Settings\ChromeLook;

// How the header and footer look, the current page in the menu, and the mobile menu
// (PLAN.md D-032, D-036). Asserted on the served page: a class resolved and never emitted
// is the same as a choice that does nothing. adminSite() and adminPost() come from
// pages_admin_test.php.

/**
 * A home page, an about page and a header menu pointing at both, with the second holding
 * one child: the smallest site with something to mark and something to fold.
 */
function lookSite(App\Core\Db $db): void
{
    $home = createPage($db, 'en', '', 'Home');
    $about = createPage($db, 'en', 'about', 'About');
    $team = createPage($db, 'en', 'team', 'Team');
    $menu = Menu::create($db, 'en', 'Main');
    Menu::addItem($db, $menu, null, $home, null, 'Home');
    $parent = (int) Menu::addItem($db, $menu, null, $about, null, 'About');
    Menu::addItem($db, $menu, $parent, $team, null, 'Team');
    Settings::set($db, 'chrome_menu', 'Main');
}

/** The opening tag of the site's <header> element, where the section layers are classes. */
function headerTag(string $body): string
{
    return preg_match('~<header class="[^"]*"~', $body, $match) === 1 ? $match[0] : '';
}

testBothDrivers('the chrome dresses as the character says until the owner chooses', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);
    Composition::remember($db, 'brutalist');

    $body = dispatch('/')->body;
    $tag = headerTag($body);
    assertContains('layout-left', $tag, 'Brutalist\'s arrangement');
    assertContains('surface-contrast', $tag, 'Brutalist\'s header surface');
    assertContains('site-header density-compact logo-large has-rule', $body, 'Brutalist\'s density, logo size and rule');

    ChromeLook::save($db, ['header_layout' => 'sticky', 'header_surface' => 'tinted', 'header_rule' => 'off']);
    $body = dispatch('/')->body;
    $tag = headerTag($body);
    assertContains('layout-sticky', $tag, 'the owner\'s arrangement');
    assertContains('surface-tinted', $tag, 'the owner\'s surface');
    assertTrue(!str_contains($body, 'has-rule'), 'the rule the owner switched off');
    // Choices left alone still follow the character.
    assertContains('density-compact', $body, 'a choice left to the character');

    Composition::remember($db, 'soft');
    assertContains('density-roomy', dispatch('/')->body, 'changing character re-dresses what the owner left alone');
});

test('a value outside a closed set is stored as "follow the character"', function () {
    $db = installedSite(['en' => 'English']);
    ChromeLook::save($db, ['header_layout' => 'floating', 'density' => '<script>', 'logo_size' => 'large']);

    $stored = ChromeLook::stored($db);
    assertEquals('', $stored['header_layout'], 'an unknown arrangement');
    assertEquals('', $stored['density'], 'an unknown density');
    assertEquals('large', $stored['logo_size'], 'a real choice');
});

test('every character answers every choice from its closed set', function () {
    foreach (App\Modules\Design\Presets::names() as $character) {
        assertTrue(isset(ChromeLook::CHARACTER[$character]), "{$character} gives its chrome nothing");
        foreach (ChromeLook::OPTIONS as $choice => $options) {
            $value = ChromeLook::CHARACTER[$character][$choice] ?? null;
            assertTrue(in_array($value, $options, true), "{$character}: {$choice} is " . var_export($value, true));
        }
    }
});

testBothDrivers('the menu marks the page being drawn, and its parent', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);

    $about = dispatch('/about')->body;
    assertContains('<a href="/about" aria-current="page">About</a>', $about, 'the current page');
    assertTrue(!str_contains($about, '<a href="/" aria-current'), 'another entry marked');

    $team = dispatch('/team')->body;
    assertContains('<a href="/team" aria-current="page">Team</a>', $team, 'a current child');
    assertContains('<li class="is-current-parent">', $team, 'its parent');

    // An error page is no page: nothing is marked.
    assertTrue(!str_contains(dispatch('/nowhere')->body, 'aria-current="page"'), 'the 404 marked an entry');
});

testBothDrivers('the mobile menu is a script that adds buttons, never a page that needs one', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);

    $body = dispatch('/')->body;
    assertContains('assets/site-nav.js', $body, 'the script');
    // Born hidden: without the script there is no button that does nothing.
    assertContains('aria-controls="site-nav" hidden data-site-nav-toggle', $body, 'the menu button');
    assertContains('data-site-nav-more', $body, 'the submenu button');

    Settings::set($db, 'chrome_menu', '');
    assertTrue(!str_contains(dispatch('/')->body, 'site-nav.js'), 'the script loaded with no menu to fold');
});

testBothDrivers('the Appearance screen saves the look', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', appearanceFields([
        'look_header_layout' => 'centred',
        'look_density' => '',
        'look_logo_size' => 'small',
    ])));
    $stored = ChromeLook::stored($db);
    assertEquals('centred', $stored['header_layout'], 'the arrangement');
    assertEquals('', $stored['density'], 'a choice left to the character');
    assertEquals('small', $stored['logo_size'], 'the logo size');

    assertContains('value="centred" checked', dispatch('/admin/appearance')->body, 'the screen shows it');
});

testBothDrivers('the site can say what made it, and says nothing unless asked', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');

    // Off is the default, and the default is what a site that never opens Settings has.
    $quiet = dispatch('/')->body;
    assertTrue(!str_contains($quiet, 'site-credit'), 'a credit nobody asked for');
    assertTrue(!str_contains($quiet, 'boxlet.org'), 'and no address for one');

    Settings::set($db, 'site_credit', true);
    $credited = dispatch('/')->body;
    assertContains('class="site-credit"', $credited, 'the line');
    assertContains('href="https://boxlet.org"', $credited, 'where it leads');
    assertContains(site_t('site.credit', 'en'), $credited, 'what it says');
    // It is the last thing on the page, in the small print, not a badge of its own.
    assertContains('site-small-print', $credited, 'where it sits');

    // A site whose footer has nothing else in it still draws one for this: without that,
    // the switch would be on and the line nowhere.
    assertEquals(1, preg_match_all('~<footer~', $credited), 'exactly one footer');

    // In the language of the page, like everything else a visitor reads (D-044).
    App\Modules\Languages\Locales::add($db, 'hr');
    createPage($db, 'hr', '', 'Naslovnica');
    assertContains(site_t('site.credit', 'hr'), dispatch('/hr/')->body, 'the credit in Croatian');
});
