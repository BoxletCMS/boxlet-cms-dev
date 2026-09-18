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

testBothDrivers('a logo set on the header screen before the merge still shows, until Branding is saved', function (string $driver) {
    $db = adminSite($driver);
    $old = storedPicture($db, 'old.png', ['full' => ['width' => 400, 'height' => 100, 'formats' => ['png']]]);
    $db->query("INSERT INTO settings (`key`, value_json) VALUES ('chrome_logo', ?)", [json_encode($old)]);

    assertEquals($old, SiteChrome::logo($db), 'the older header logo is not read');
    assertContains('<option value="' . $old . '" data-thumb=', dispatch('/admin/settings')->body, 'Branding does not show the logo the header draws');

    assertRedirectedTo('/admin/settings', adminPost('/admin/settings', [
        'site_name' => 'Studio', 'timezone' => 'UTC', 'site_logo' => (string) $old,
    ]));
    assertEquals($old, Settings::mediaId($db, 'site_logo'), 'the logo moved to Branding');
    assertEquals(null, Settings::mediaId($db, 'chrome_logo'), 'the older setting is still there to confuse');

    // Cleared in Branding means no logo: the retired one does not come back.
    adminPost('/admin/settings', ['site_name' => 'Studio', 'timezone' => 'UTC', 'site_logo' => '']);
    assertEquals(null, SiteChrome::logo($db), 'the retired logo came back');
});
