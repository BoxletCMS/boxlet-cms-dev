<?php

use App\Core\Settings;

// The site settings screen (PLAN.md D-028), through the admin as a person uses it.
// adminSite() and adminPost() come from pages_admin_test.php; run.php requires every test
// file before running any test, so they are defined by the time these run.

testBothDrivers('the settings screen shows what is stored', function (string $driver) {
    $db = adminSite($driver);
    Settings::set($db, 'site_name', 'Northwind Studio');
    Settings::set($db, 'timezone', 'Europe/Zagreb');

    $body = dispatch('/admin/settings')->body;

    assertContains('value="Northwind Studio"', $body, 'the stored site name');
    assertContains('<option value="Europe/Zagreb" selected>', $body, 'the stored time zone');
    // The screen is reachable from every other one, not only by typing the address.
    assertContains('href="/admin/settings"', $body, 'the navigation has no link to it');
});

testBothDrivers('saving writes the settings the installer wrote, rather than keys beside them', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/settings', adminPost('/admin/settings', [
        'site_name' => 'Renamed',
        'timezone' => 'Europe/London',
        'maintenance_message' => 'Back in an hour.',
    ]));

    // The installer's own keys, edited — not site_title or tz or anything beside them.
    assertEquals('Renamed', Settings::get($db, 'site_name'), 'site_name');
    assertEquals('Europe/London', Settings::get($db, 'timezone'), 'timezone');
    assertEquals('Back in an hour.', Settings::get($db, 'maintenance_message'), 'maintenance_message');
});

testBothDrivers('a time zone this server does not know is refused and nothing is written', function (string $driver) {
    $db = adminSite($driver);
    Settings::set($db, 'site_name', 'Before');
    Settings::set($db, 'timezone', 'Europe/Zagreb');

    $response = adminPost('/admin/settings', [
        'site_name' => 'After',
        'timezone' => 'Mars/Olympus_Mons',
        'maintenance_message' => '',
    ]);

    assertEquals(422, $response->status, 'status');
    // Nothing at all: a screen that refused one field and saved the rest would leave the
    // owner unable to tell what went in.
    assertEquals('Before', Settings::get($db, 'site_name'), 'the site name was written anyway');
    assertEquals('Europe/Zagreb', Settings::get($db, 'timezone'), 'the time zone was written anyway');
    // And the refused value is on the screen to correct, not the stored one.
    assertContains('value="After"', $response->body, 'the submitted name is not in the form');
});

testBothDrivers('a picture id that names nothing is cleared rather than stored', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/settings', adminPost('/admin/settings', [
        'site_name' => 'Studio',
        'timezone' => 'Europe/Zagreb',
        'maintenance_message' => '',
        // The library is empty in this test, so nothing answers to 4321. The same rule
        // MediaReference sets for block content: an id that names no picture becomes null.
        'site_favicon' => '4321',
    ]));

    assertEquals(null, Settings::mediaId($db, 'site_favicon'), 'a dangling id was stored');
});

test('the settings screen and its save need an admin session', function () {
    installedSite(['en' => 'English']);

    assertEquals(302, dispatch('/admin/settings')->status, 'the screen is open to anyone');
    // No token either: the router refuses a state-changing request without one before it
    // reaches the controller, so this asserts the route is guarded at all.
    assertEquals(403, dispatch('/admin/settings', null, 'POST', ['site_name' => 'Sneaky'])->status, 'saving without a token');
});
