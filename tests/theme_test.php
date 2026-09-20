<?php

use App\Core\Request;
use App\Modules\Admin\Theme;
use App\Support\Url;

// Light, dark, or whichever the machine is set to (PLAN.md D-054). adminSite() comes from
// pages_admin_test.php and adminPost() from the same file.

/** A request carrying $cookies in its header. */
function themeRequest(string $cookies): Request
{
    return new Request('GET', '/admin', '', [], [], $cookies === '' ? [] : ['cookie' => $cookies]);
}

test('the palette is read from the cookie, and anything else is the dark default', function () {
    assertEquals('dark', Theme::of(themeRequest('')), 'no cookie at all');
    assertEquals('light', Theme::of(themeRequest('boxlet_theme=light')), 'the preference');
    assertEquals('system', Theme::of(themeRequest('boxlet_rail=wide; boxlet_theme=system')), 'beside another cookie');
    assertEquals('dark', Theme::of(themeRequest('boxlet_theme=magenta')), 'a value nobody offers');
    // The name has to match whole: a cookie ending in the same letters is a different cookie.
    assertEquals('dark', Theme::of(themeRequest('other_boxlet_theme=light')), 'a cookie with a longer name');
});

test('the cookie is the admin\'s alone, and lasts', function () {
    $cookie = Theme::cookie('light');
    assertContains('boxlet_theme=light', $cookie, 'the value');
    assertContains('Path=/admin', $cookie, 'the public site never carries it');
    assertContains('HttpOnly', $cookie, 'no script needs to read it');
    assertTrue((bool) preg_match('~Max-Age=\d{7,}~', $cookie), 'it outlives the browser session');
});

testBothDrivers('the admin is drawn in the palette the cookie asks for', function (string $driver) {
    adminSite($driver);

    $_SERVER['HTTP_COOKIE'] = 'boxlet_theme=light';
    try {
        $html = dispatch('/admin')->body;
    } finally {
        unset($_SERVER['HTTP_COOKIE']);
    }

    assertContains('data-ui-theme="light"', $html, 'the attribute the palette hangs on');
    // Server-rendered, which is what leaves no flash of the other theme and needs no
    // script the admin's CSP would refuse anyway.
    assertContains('assets/admin-tokens.css', $html, 'the palettes are linked');
    // The switch says which one is current, in a way a screen reader can hear.
    assertTrue(
        substr_count($html, 'aria-pressed="true"') === 1,
        'exactly one of the three choices is marked as the current one',
    );
    assertContains('value="light"', $html, 'the three choices are really there');

    $plain = dispatch('/admin')->body;
    assertContains('data-ui-theme="dark"', $plain, 'with no cookie, the admin is dark as it shipped');
});

testBothDrivers('pressing a choice remembers it and comes back to the same screen', function (string $driver) {
    adminSite($driver);

    $response = adminPost('/admin/appearance', ['theme' => 'light', 'back' => '/admin/pages?q=x']);
    assertEquals(302, $response->status, 'it redirects');
    assertEquals('/admin/pages?q=x', $response->headers['Location'] ?? null, 'back to where it was pressed');
    assertContains('boxlet_theme=light', $response->headers['Set-Cookie'] ?? '', 'the choice is kept');

    $system = adminPost('/admin/appearance', ['theme' => 'system', 'back' => Url::admin()]);
    assertContains('boxlet_theme=system', $system->headers['Set-Cookie'] ?? '', 'match the system is a choice too');
});

testBothDrivers('the switch cannot be made to send anyone anywhere else', function (string $driver) {
    adminSite($driver);

    // Every one of these is a real open-redirect shape. The form sends `back`, so the
    // browser is the one saying where to return to, and a browser can be told to say
    // anything.
    foreach (['https://example.com/', '//example.com/', '/admin//example.com', '/pages', "/admin\r\nX: 1"] as $back) {
        $response = adminPost('/admin/appearance', ['theme' => 'dark', 'back' => $back]);
        assertEquals(Url::admin(), $response->headers['Location'] ?? null, "back={$back} must land on the dashboard");
    }

    // And a palette nobody offers is simply the default, not a value written into a header.
    $odd = adminPost('/admin/appearance', ['theme' => 'magenta; Domain=example.com', 'back' => Url::admin()]);
    assertContains('boxlet_theme=dark', $odd->headers['Set-Cookie'] ?? '', 'an unknown palette falls back');
});
