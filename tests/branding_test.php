<?php

use App\Core\Settings;
use App\Modules\Settings\SiteChrome;

// One logo for the site, set under Settings → Branding, keeping its shape (D-038).
// storedPicture() records variants without writing files, which is all a page's markup needs.

testBothDrivers('the header draws the Branding logo, uncropped', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $logo = storedPicture($db, 'wordmark.png', [
        'thumb' => ['width' => 200, 'height' => 200, 'formats' => ['png']],
        'full' => ['width' => 800, 'height' => 200, 'formats' => ['png']],
    ]);
    Settings::set($db, 'site_logo', $logo);

    $body = dispatch('/')->body;
    assertContains('/m/full/' . $logo . '-wordmark.png', $body, 'the logo is not the uncropped variant');
    assertTrue(!str_contains($body, '/m/thumb/' . $logo . '-'), 'the logo was drawn from the square crop');
});

testBothDrivers('Branding keeps a second logo for dark surfaces, and clearing it takes only that one', function (string $driver) {
    $db = adminSite($driver);
    $logo = storedPicture($db, 'mark.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);
    $dark = storedPicture($db, 'mark-dark.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);

    assertContains('name="site_logo_dark"', dispatch('/admin/settings')->body, 'the field, under Branding');
    assertRedirectedTo('/admin/settings', adminPost('/admin/settings', [
        'site_name' => 'Studio', 'timezone' => 'UTC', 'site_logo' => (string) $logo, 'site_logo_dark' => (string) $dark,
    ]));
    assertEquals($dark, Settings::mediaId($db, 'site_logo_dark'), 'the second logo');
    assertEquals($dark, SiteChrome::header($db, 'en')['logo_dark'], 'and the header is handed it');

    adminPost('/admin/settings', ['site_name' => 'Studio', 'timezone' => 'UTC', 'site_logo' => (string) $logo, 'site_logo_dark' => '']);
    assertEquals(null, Settings::mediaId($db, 'site_logo_dark'), 'cleared');
    assertEquals($logo, SiteChrome::logo($db), 'the first logo untouched');
});
