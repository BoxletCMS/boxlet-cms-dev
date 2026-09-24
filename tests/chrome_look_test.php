<?php

use App\Core\Settings;
use App\Modules\Design\Composition;
use App\Modules\Menus\Menu;
use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\ChromeWords;
use App\Modules\Settings\SiteChrome;

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
    assertContains('layout-split', $tag, 'Brutalist\'s arrangement');
    assertContains('surface-contrast', $tag, 'Brutalist\'s header surface');
    assertContains('site-header density-compact logo-large behaviour-static edge-shadow nav-caps nav-ink-accent button-outline brand-logo', $body, 'Brutalist\'s density, logo size, behaviour, edge, menu, button and brand');

    ChromeLook::save($db, ['header_behaviour' => 'sticky', 'header_surface' => 'tinted', 'header_edge' => 'none']);
    $body = dispatch('/')->body;
    $tag = headerTag($body);
    assertContains('behaviour-sticky', $body, 'the owner\'s behaviour');
    assertContains('surface-tinted', $tag, 'the owner\'s surface');
    assertContains('edge-none', $body, 'the edge the owner took away');
    // Choices left alone still follow the character.
    assertContains('density-compact', $body, 'a choice left to the character');
    assertContains('layout-split', headerTag($body), 'the arrangement left to the character');

    Composition::remember($db, 'soft');
    assertContains('density-roomy', dispatch('/')->body, 'changing character re-dresses what the owner left alone');
});

test('a value outside a closed set is stored as "follow the character"', function () {
    $db = installedSite(['en' => 'English']);
    ChromeLook::save($db, ['header_arrangement' => 'floating', 'density' => '<script>', 'logo_size' => 'large']);

    $stored = ChromeLook::stored($db);
    assertEquals('', $stored['header_arrangement'], 'an unknown arrangement');
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
    // Named for a screen reader and drawn as three lines (D-114), born hidden.
    assertContains('aria-controls="site-nav" aria-label="Menu" hidden data-site-nav-toggle', $body, 'the menu button');
    assertContains('data-site-nav-more', $body, 'the submenu button');

    Settings::set($db, 'chrome_menu', '');
    assertTrue(!str_contains(dispatch('/')->body, 'site-nav.js'), 'the script loaded with no menu to fold');
});

testBothDrivers('the Appearance screen saves the look', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', appearanceFields([
        'look_header_arrangement' => 'centred',
        'look_density' => '',
        'look_logo_size' => 'small',
    ])));
    $stored = ChromeLook::stored($db);
    assertEquals('centred', $stored['header_arrangement'], 'the arrangement');
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

/*
 * A COLOUR OF THE OWNER'S OWN REACHES THE LINKS (D-110).
 *
 * D-076 gave the header and the footer a colour of their own and derived a readable ink for
 * it — and then set the section's tokens on the container with the token itself in the
 * fallback, which is a cycle, which is a token that quietly becomes nothing wherever the
 * primary is absent — which is every site with no colour of its own: measured, a contrast
 * footer's links took the page's accent at 2.43:1. The class this asserts is what lets
 * chrome.css set the tokens with no fallback at all; both halves are checked — the class
 * when there is a colour, and its absence when there is none, so a class that is always
 * emitted cannot pass.
 */
testBothDrivers('a colour of the owner\'s own is a class on the bar, and only then', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    $plain = dispatch('/')->body;
    assertTrue(!str_contains($plain, 'own-colour'), 'no colour was set, so no bar claims one');

    // The menu too: the screen is one form, and a field it does not send is one the owner
    // cleared (D-059) — without it the header would have nothing left to draw.
    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'footer_colour' => '#f3e9d2', 'footer_colour_on' => '1',
        'action' => 'save',
    ]));
    $coloured = dispatch('/')->body;
    assertContains('site-header density-', $coloured, 'the header');
    assertTrue(preg_match('~class="site-header [^"]*own-colour~', $coloured) === 1, 'the header wears its own colour');
    assertTrue(preg_match('~class="site-footer [^"]*own-colour~', $coloured) === 1, 'the footer wears its own colour');

    // The preview reads the same decisions from its query, so the class and the tokens
    // come from one place there too.
    $preview = dispatch('/admin/appearance/preview?' . http_build_query(appearanceFields([
        'header_menu' => 'Main',
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'footer_colour_on' => '0',
    ])))->body;
    assertTrue(preg_match('~class="site-header [^"]*own-colour~', $preview) === 1, 'the preview\'s header, from the query');
    assertTrue(preg_match('~class="site-footer [^"]*own-colour~', $preview) === 0, 'the preview\'s footer, whose switch is off');

    // A header laid over the first section paints nothing and takes the colours beneath
    // it: the two choices contradict each other, and the behaviour wins (D-076).
    ChromeLook::save($db, ['header_behaviour' => 'over']);
    $over = dispatch('/')->body;
    assertContains('behaviour-over', $over, 'the header is over the first section');
    assertTrue(preg_match('~class="site-header [^"]*own-colour~', $over) === 0, 'and claims no colour of its own there');
});

/*
 * THE SITE'S NAME STANDS WHERE THE LOGO WOULD (D-110). A header that drew only the menu left
 * a site with no logo — which is most sites on their first day — without its name anywhere
 * on the page.
 */
testBothDrivers('a site without a logo puts its name in the header', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);
    Settings::set($db, 'site_name', 'Northwind & Co');

    $body = dispatch('/')->body;
    assertTrue(preg_match('~<a class="site-logo site-name" href="[^"]*">Northwind &amp; Co</a>~', $body) === 1, 'the name, escaped, linking home');

    // A site with nothing else in its header — no menu, no button — still has a name, so it
    // still has a header.
    $bare = installedSite(['en' => 'English'], $driver);
    createPage($bare, 'en', '', 'Home');
    Settings::set($bare, 'site_name', 'Bare');
    $alone = dispatch('/')->body;
    assertContains('<header class="', $alone, 'a header for the name alone');
    assertContains('site-name', $alone, 'and the name in it');
    assertTrue(!str_contains($alone, 'site-nav'), 'with no nav drawn for a menu that is not there');
});

/*
 * THE OLD NAMES ARE STILL READ (D-112). `header_layout` held arrangement and behaviour as
 * one choice, and `header_rule` was on or off; a site that saved either before this and
 * nothing since keeps the header it had — and a kept design's row too, through the same
 * function. The new choices win the moment one is saved.
 */
testBothDrivers('a header saved under the old names keeps the header it had', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);
    Composition::remember($db, 'minimal');
    Settings::set($db, 'chrome_look_header_layout', 'transparent');
    Settings::set($db, 'chrome_look_header_rule', 'on');

    $stored = ChromeLook::stored($db);
    assertEquals('left', $stored['header_arrangement'], 'transparent meant left');
    assertEquals('over', $stored['header_behaviour'], 'and over the first section');
    assertEquals('line', $stored['header_edge'], 'the rule that was on');
    assertTrue(!isset($stored['header_layout']), 'the old name is not a choice any more');

    $body = dispatch('/')->body;
    assertContains('layout-left', headerTag($body), 'drawn left');
    assertContains('behaviour-over edge-line', $body, 'over the first section, with its line');

    // A new choice, once saved, is the answer; the old row is a fact about the past.
    ChromeLook::save($db, ['header_behaviour' => 'sticky']);
    $stored = ChromeLook::stored($db);
    assertEquals('sticky', $stored['header_behaviour'], 'the new choice wins');
    assertEquals('left', $stored['header_arrangement'], 'the half the old value still answers for');

    // A kept design's look goes through the same reading.
    assertEquals(['header_arrangement' => 'centred', 'header_behaviour' => 'static', 'header_edge' => 'none'],
        array_intersect_key(ChromeLook::modernise(['header_layout' => 'centred', 'header_rule' => 'off']), ['header_arrangement' => 1, 'header_behaviour' => 1, 'header_edge' => 1]),
        'a look kept before D-112');
});

/*
 * EVERY ARRANGEMENT AND BEHAVIOUR REACHES THE PAGE AS A CLASS (D-112), and split draws its
 * menu as two lists around the name — one nav, so the phone's one button folds both.
 */
testBothDrivers('every arrangement is drawn, and split puts the name in the middle of its menu', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);
    Settings::set($db, 'site_name', 'Northwind');

    foreach (ChromeLook::OPTIONS['header_arrangement'] as $arrangement) {
        ChromeLook::save($db, ['header_arrangement' => $arrangement]);
        assertContains('layout-' . $arrangement, headerTag(dispatch('/')->body), $arrangement);
    }
    // Split, chosen on purpose: the loop above ends on masthead.
    ChromeLook::save($db, ['header_arrangement' => 'split']);
    $split = dispatch('/')->body;
    assertEquals(1, substr_count($split, '<nav class="site-nav"'), 'one nav');
    // The header's nav alone: the footer draws a list of its own.
    assertTrue(preg_match('~<nav class="site-nav".*?</nav>~s', $split, $found) === 1, 'the nav');
    $nav = $found[0] ?? '';
    // Two bare lists (a submenu's carries a class), one item in each: the two top-level
    // items, one a side of the name.
    assertEquals(2, substr_count($nav, '<ul>'), 'two lists in it');
    assertEquals(2, preg_match_all('~<ul>\s*<li[ >]~', $nav), 'each list opens with an item');
    assertEquals(1, preg_match_all('~<li[^>]*>\s*<a[^>]*>About</a>~', $nav), 'About stands in one of them');

    foreach (ChromeLook::OPTIONS['header_behaviour'] as $behaviour) {
        ChromeLook::save($db, ['header_behaviour' => $behaviour]);
        assertContains('behaviour-' . $behaviour, dispatch('/')->body, $behaviour);
    }
});

/*
 * WHAT STANDS FOR THE SITE (D-112): the logo, the name, or both — and the name whatever the
 * choice says when there is no logo (D-110).
 */
testBothDrivers('the brand is the logo, the name, or both, and the name when there is no logo', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);
    Settings::set($db, 'site_name', 'Northwind');
    $logo = storedPicture($db, 'mark.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);
    Settings::set($db, 'site_logo', $logo);

    ChromeLook::save($db, ['brand' => 'logo']);
    $body = dispatch('/')->body;
    assertContains('/m/full/' . $logo . '-mark.png', $body, 'the logo');
    assertTrue(!str_contains($body, 'site-name'), 'and no name beside it');

    ChromeLook::save($db, ['brand' => 'name']);
    $body = dispatch('/')->body;
    assertContains('class="site-logo site-name"', $body, 'the name alone');
    assertTrue(!str_contains($body, '/m/full/' . $logo), 'and no logo');

    ChromeLook::save($db, ['brand' => 'both']);
    $body = dispatch('/')->body;
    assertContains('class="site-logo site-brand"', $body, 'both in one link');
    assertContains('<span class="site-name">Northwind</span>', $body, 'the name after the picture');
    assertContains('/m/full/' . $logo . '-mark.png', $body, 'and the picture');

    Settings::set($db, 'site_logo', null);
    ChromeLook::save($db, ['brand' => 'logo']);
    assertContains('class="site-logo site-name"', dispatch('/')->body, 'no logo: the name, whatever the choice');
});

/*
 * THE LOGO FOR DARK SURFACES (D-112) is chosen by the INK the palette puts on the header,
 * never by the surface's name: a contrast surface can be cream, and a page set dark by hand
 * makes a plain header dark. Over the first section it is that section's ink.
 */
testBothDrivers('the dark-surface logo is drawn where the ink on the header is light', function (string $driver) {
    // adminSite, not installedSite: the last case publishes through the screen, which
    // needs the admin's session.
    $db = adminSite($driver);
    lookSite($db);
    Composition::remember($db, 'minimal');
    $light = storedPicture($db, 'mark.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);
    $dark = storedPicture($db, 'mark-dark.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);
    Settings::set($db, 'site_logo', $light);
    Settings::set($db, 'site_logo_dark', $dark);

    $drawn = static fn (): string => str_contains(dispatch('/')->body, '/m/full/' . $dark . '-mark-dark.png') ? 'dark' : 'light';

    ChromeLook::save($db, ['header_surface' => 'plain', 'header_behaviour' => 'static']);
    assertEquals('light', $drawn(), 'Minimal\'s plain header is pale');
    ChromeLook::save($db, ['header_surface' => 'contrast', 'header_behaviour' => 'static']);
    assertEquals('dark', $drawn(), 'Minimal\'s contrast surface is graphite, so its ink is light');
    ChromeLook::save($db, ['header_surface' => 'gradient', 'header_behaviour' => 'static']);
    assertEquals('dark', $drawn(), 'and so is the gradient\'s');

    // Over the first section: that section decides. The home page's first section is plain.
    ChromeLook::save($db, ['header_surface' => 'contrast', 'header_behaviour' => 'over']);
    assertEquals('light', $drawn(), 'over a plain first section the ink is dark');

    // A colour of the owner's own: the ink derived for it decides.
    ChromeLook::save($db, ['header_surface' => 'plain', 'header_behaviour' => 'static']);
    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'header_colour' => '#111111', 'header_colour_on' => '1', 'action' => 'save']));
    assertEquals('dark', $drawn(), 'a near-black header of the owner\'s own');

    // Without a second logo there is nothing to choose: the site's logo, on every surface.
    Settings::set($db, 'site_logo_dark', null);
    ChromeLook::save($db, ['header_surface' => 'contrast']);
    assertContains('/m/full/' . $light . '-mark.png', dispatch('/')->body, 'the one logo the site has');
});

/*
 * THE FOOTER'S FIVE ARRANGEMENTS, ITS EDGE AND ITS LAST ROW (D-113) reach the page as
 * classes; the edge is a section divider, drawn by sections.css exactly as on a band.
 */
testBothDrivers('the footer is drawn in every arrangement, with its edge and its last row', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    lookSite($db);
    Settings::set($db, 'site_credit', true);

    foreach (ChromeLook::OPTIONS['footer_layout'] as $layout) {
        ChromeLook::save($db, ['footer_layout' => $layout]);
        assertTrue(preg_match('~<footer class="[^"]*layout-' . $layout . '~', dispatch('/')->body) === 1, $layout);
    }
    foreach (ChromeLook::OPTIONS['footer_edge'] as $edge) {
        ChromeLook::save($db, ['footer_edge' => $edge]);
        assertTrue(preg_match('~<footer class="[^"]*divider-' . $edge . '~', dispatch('/')->body) === 1, $edge);
    }
    foreach (ChromeLook::OPTIONS['small_print_row'] as $row) {
        ChromeLook::save($db, ['small_print_row' => $row]);
        $body = dispatch('/')->body;
        assertContains('foot-' . $row, $body, $row);
        assertContains('<div class="site-footer-foot">', $body, 'the last row, with the credit in it');
    }
});

/*
 * EACH FOOTER COLUMN HAS A MENU (D-115): none, the header's, or one of its own — and a
 * renamed menu is followed there too. Column 1 shows the header's menu until something is
 * saved for it, which is what every footer showed before a menu could be chosen (D-028).
 */
testBothDrivers('a footer column shows no menu, the header\'s, or one of its own', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);
    // The credit, so the footer has something to draw with no menu and no words in it: a
    // footer with nothing to show is not drawn, which is right and not what this tests.
    Settings::set($db, 'site_credit', true);
    $legal = Menu::create($db, 'en', 'Legal');
    Menu::addItem($db, $legal, null, createPage($db, 'en', 'privacy', 'Privacy'), null, 'Privacy');

    $footerNavs = static function (string $body): array {
        preg_match_all('~<nav class="site-footer-nav">.*?</nav>~s', $body, $m);

        return $m[0];
    };

    $navs = $footerNavs(dispatch('/')->body);
    assertEquals(1, count($navs), 'one menu, in column 1');
    assertContains('>About<', $navs[0], 'the header\'s menu, before anything is saved');

    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'footer_menu_1' => 'Legal', 'action' => 'save']));
    assertEquals([1 => 'Legal', 2 => 'none', 3 => 'none'], SiteChrome::footerMenus($db), 'stored by name, per column');
    $body = dispatch('/')->body;
    $navs = $footerNavs($body);
    assertContains('>Privacy<', $navs[0] ?? '', 'the column\'s own menu');
    assertTrue(!str_contains($navs[0] ?? '', '>About<'), 'and not the header\'s');
    assertContains('<a href="/about"', $body, 'the header still has its own');

    // Two columns drawn, the second with the header's menu.
    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'footer_menu_1' => 'Legal', 'footer_menu_2' => SiteChrome::FOOTER_MENU_HEADER, 'look_footer_layout' => 'columns', 'action' => 'save']));
    $navs = $footerNavs(dispatch('/')->body);
    assertEquals(2, count($navs), 'two columns, a menu in each');
    assertContains('>About<', $navs[1], 'the header\'s in the second');

    // Saved as none: no menu, and the footer itself still drawn.
    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'footer_menu_1' => SiteChrome::FOOTER_MENU_NONE, 'action' => 'save']));
    $body = dispatch('/')->body;
    assertEquals([], $footerNavs($body), 'no menu in the footer');
    assertContains('<footer class="', $body, 'the footer itself still drawn');

    // A menu that is not there any more is cleared rather than stored (as the header's).
    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'footer_menu_1' => 'Gone', 'action' => 'save']));
    assertEquals(SiteChrome::FOOTER_MENU_NONE, SiteChrome::footerMenus($db)[1], 'a name no menu carries');

    // A rename is followed in a column as in the header.
    adminPost('/admin/appearance', appearanceFields(['header_menu' => 'Main', 'footer_menu_1' => 'Legal', 'action' => 'save']));
    adminPost("/admin/menus/{$legal}/rename", ['name' => 'Small print']);
    assertEquals('Small print', SiteChrome::footerMenus($db)[1], 'the column followed the rename');
    assertContains('>Privacy<', $footerNavs(dispatch('/')->body)[0] ?? '', 'and still draws it');

    // What D-113 stored for one day — '' for the header's, `none` for none — reads exactly.
    Settings::set($db, 'chrome_footer_menu', '');
    assertEquals(SiteChrome::FOOTER_MENU_HEADER, SiteChrome::footerMenus($db)[1], "'' is the header's, as it was");
    assertContains('>About<', $footerNavs(dispatch('/')->body)[0] ?? '', 'and draws it');
    Settings::set($db, 'chrome_footer_menu', 'Small print');

    // The preview draws the menu being tried, and writes nothing.
    $tried = dispatch('/admin/appearance/preview?footer_menu_1=' . SiteChrome::FOOTER_MENU_NONE)->body;
    assertEquals([], $footerNavs($tried), 'the preview with no menu in column 1');
    assertEquals('Small print', SiteChrome::footerMenus($db)[1], 'nothing written');
});

/*
 * A COLUMN IS DRAWN WHEN IT HAS SOMETHING, AND ONLY AS MANY AS THE ARRANGEMENT SHOWS (D-115).
 * A title is a heading; what is typed for a column past the arrangement's count is kept.
 */
testBothDrivers('the footer draws the columns that have content, up to the arrangement\'s count', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'look_footer_layout' => 'three',
        'footer_title_en' => 'Studio', 'footer_text_en' => '<p>Ilica 1, Zagreb</p>',
        'footer_col2_title_en' => 'Hours', 'footer_col2_text_en' => '<p>Mon–Fri 9–17</p>',
        'footer_col3_title_en' => 'Legal', 'footer_menu_3' => 'Main',
        'action' => 'save',
    ]));
    $body = dispatch('/')->body;
    assertEquals(3, substr_count($body, 'class="site-footer-col"'), 'three columns');
    assertContains('<h2 class="site-footer-title">Studio</h2>', $body, 'a title is a heading');
    assertContains('<h2 class="site-footer-title">Hours</h2>', $body, 'the second');
    assertContains('drawn-3', $body, 'and the footer says how many it drew');
    assertContains('Mon–Fri 9–17', $body, 'words in the second');

    // One column drawn: the rest kept, not shown.
    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'look_footer_layout' => 'simple',
        'footer_title_en' => 'Studio', 'footer_text_en' => '<p>Ilica 1, Zagreb</p>',
        'footer_col2_title_en' => 'Hours', 'footer_col2_text_en' => '<p>Mon–Fri 9–17</p>',
        'footer_col3_title_en' => 'Legal', 'footer_menu_3' => 'Main',
        'action' => 'save',
    ]));
    $body = dispatch('/')->body;
    assertEquals(1, substr_count($body, 'class="site-footer-col"'), 'one column');
    assertTrue(!str_contains($body, 'Hours'), 'the second not drawn');
    assertEquals('Hours', SiteChrome::footer($db, 'en')['columns'][1]['title'], 'but kept');
    assertContains('drawn-1', $body, 'one drawn');
});

/*
 * THE FOOTER'S TEXT IS RICH TEXT (D-113), with a short whitelist: a link, bold, italic, a
 * paragraph. What was stored before is plain and draws exactly as it did.
 */
testBothDrivers('the footer\'s text keeps a link and loses a heading, and a plain text from before draws as it did', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'footer_text_en' => '<h2>Studio</h2><p>Write to <a href="hello@example.com">us</a> or <b>call</b> <script>x()</script>+385 91 234 5678</p><ul><li>one</li></ul>',
        'action' => 'save',
    ]));
    $stored = SiteChrome::footer($db, 'en')['columns'][0]['text'];
    assertTrue(!str_contains($stored, '<h2>'), 'a heading is not a footer\'s: ' . $stored);
    assertTrue(!str_contains($stored, '<ul>') && !str_contains($stored, '<script'), 'nor a list or a script: ' . $stored);
    assertContains('<a href="mailto:hello@example.com">us</a>', $stored, 'an email becomes the link it meant (D-039)');
    assertContains('<b>call</b>', $stored, 'bold stays');
    assertContains('Studio', $stored, 'the heading\'s words stay');

    $body = dispatch('/')->body;
    assertContains('<a href="mailto:hello@example.com">us</a>', $body, 'the link reaches the visitor');

    // Plain text from before D-113, with a line break: drawn as it always was, and handed
    // to the editor as one paragraph with its break.
    Settings::set($db, 'chrome_footer_text:en', "Line one\nLine two & co");
    $body = dispatch('/')->body;
    // <br>, not nl2br's <br />: the block machinery cleans a rich text field on the way to
    // the template, and the DOM writes a break as <br>. The same break, drawn the same.
    assertContains("Line one<br>\nLine two &amp; co", $body, 'the break kept, the ampersand escaped');
    assertContains('<p>Line one<br>' . "\n" . 'Line two &amp; co</p>', ChromeWords::asHtml("Line one\nLine two & co"), 'and the editor is handed a paragraph');
    assertContains(e('<p>Line one<br>' . "\n" . 'Line two &amp; co</p>'), dispatch('/admin/appearance')->body, 'which is what the screen holds');
});
