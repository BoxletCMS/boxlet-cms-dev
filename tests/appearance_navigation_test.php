<?php

use App\Modules\Menus\Menu;
use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\SiteChrome;

// What the header and footer say is Navigation's, how they look is Appearance's (PLAN.md
// D-180, README 5.9). They were one form (D-059), and publishing a design wrote every word
// and menu choice again from whatever was posted; the fields have moved, and nothing
// Appearance does may take them away.

/** The words and menus a site has, through Navigation as the owner sets them. */
function navigationSite(string $driver): App\Core\Db
{
    $db = adminSite($driver);
    Menu::create($db, 'en', 'Main');
    Menu::create($db, 'en', 'Legal');
    assertRedirectedTo('/admin/navigation', adminPost('/admin/navigation', [
        'header_menu' => 'Main',
        'footer_menu_1' => 'Legal',
        'footer_menu_2' => SiteChrome::FOOTER_MENU_HEADER,
        'header_button_label_en' => 'Write to us',
        'header_button_url_en' => '/contact',
        'footer_title_en' => 'Studio',
        'footer_text_en' => '<p>Ilica 1, Zagreb</p>',
        'footer_small_print_en' => '© Northwind',
    ]));

    return $db;
}

/** @return array<string, mixed> what is stored, every word and menu choice */
function navigationStored(App\Core\Db $db): array
{
    return ['menu' => SiteChrome::menuName($db), 'footer' => SiteChrome::footerMenus($db), 'header' => SiteChrome::header($db, 'en'), 'words' => SiteChrome::footer($db, 'en')];
}

testBothDrivers('Navigation saves what the header and footer say and which menus they show', function (string $driver) {
    $db = navigationSite($driver);

    assertEquals('Main', SiteChrome::menuName($db), 'the header\'s menu');
    assertEquals('Legal', SiteChrome::footerMenus($db)[1], 'the first column\'s');
    assertEquals('Write to us', SiteChrome::header($db, 'en')['button']['label'], 'the button\'s words');
    assertEquals('Studio', SiteChrome::footer($db, 'en')['columns'][0]['title'], 'a column\'s title');
    assertEquals('© Northwind', SiteChrome::footer($db, 'en')['small_print'], 'the small print');

    $page = dispatch('/admin/navigation')->body;
    assertContains('<option value="Main" selected>', $page, 'the screen shows the menu chosen');
    assertContains('value="Write to us"', $page, 'and the words');
    assertContains('href="/admin/menus/', $page, 'and the menus, each to be edited');
});

testBothDrivers('publishing Appearance never takes away a word or a menu', function (string $driver) {
    $db = navigationSite($driver);
    $before = navigationStored($db);

    // A design published, a look changed, a character loaded and published over pages that
    // have none: every way Appearance writes.
    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', appearanceFields(['seed' => '#1f1fd1', 'look_header_surface' => 'contrast', 'action' => 'save'])));
    assertEquals('contrast', ChromeLook::stored($db)['header_surface'], 'the look is Appearance\'s to write');
    assertEquals(200, adminPost('/admin/appearance', appearanceFields(['action' => 'preset:bold']))->status, 'a character loaded');
    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', appearanceFields(['character' => 'bold', 'action' => 'save'])));
    adminPost('/admin/appearance', appearanceFields(['action' => 'reset:all']));
    adminPost('/admin/appearance', appearanceFields(['action' => 'save']));

    assertEquals($before, navigationStored($db), 'every word and menu as Navigation saved it');
    assertContains('Write to us', dispatch('/')->body, 'and the page still says it');
});

test('Appearance carries no word or menu field, and says where they went', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    foreach (['header_menu', 'footer_menu_1', 'header_button_label_en', 'footer_text_en', 'footer_small_print_en'] as $field) {
        assertTrue(!str_contains($body, 'name="' . $field . '"'), $field . ' is on Appearance');
    }
    assertEquals(2, preg_match_all('~class="hint hint-always navigation-where">[^<]*<a href="/admin/navigation"~', $body), 'the header\'s and the footer\'s line to Navigation');
});

test('an address Navigation would not follow is refused, and nothing is saved', function () {
    $db = navigationSite('sqlite');

    $refused = adminPost('/admin/navigation', ['header_menu' => '', 'header_button_label_en' => 'Go', 'header_button_url_en' => 'javascript:alert(1)']);
    assertEquals(422, $refused->status, 'refused');
    assertContains(e(t('chrome.button_url_refused')), $refused->body, 'saying why');
    assertEquals('Main', SiteChrome::menuName($db), 'the menu as it was');
    assertEquals('Write to us', SiteChrome::header($db, 'en')['button']['label'], 'the words as they were');
});

test('the menus are listed on Navigation, and the old address leads there', function () {
    $db = navigationSite('sqlite');

    assertRedirectedTo('/admin/navigation', dispatch('/admin/menus'));
    $refused = adminPost('/admin/menus', ['name' => '', 'locale' => 'en']);
    assertEquals(422, $refused->status, 'a new menu with no name');
    assertContains(e(t('menus.name_required')), $refused->body, 'said on Navigation');
    assertContains('name="header_menu"', $refused->body, 'which is the whole screen');
    $legal = Menu::all($db);
    $id = 0;
    foreach ($legal as $menu) {
        $id = $menu['name'] === 'Legal' ? $menu['id'] : $id;
    }
    assertRedirectedTo('/admin/navigation', adminPost('/admin/menus/' . $id . '/delete', []));
});
