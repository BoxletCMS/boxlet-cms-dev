<?php

use App\Core\Settings;
use App\Support\Dates;

// Stored times are UTC; the owner reads them in the site's time zone.

test('a stored UTC time is shown in the site\'s zone', function () {
    assertEquals('18 Sep 2026, 11:40', Dates::local('2026-09-18 09:40:00', 'Europe/Zagreb'), 'summer time in Zagreb');
    assertEquals('18 Sep 2026, 09:40', Dates::local('2026-09-18 09:40:00', 'UTC'), 'UTC');
    assertEquals('not a date', Dates::local('not a date', 'UTC'), 'a value that is not a stored time comes back as it is');
});

testBothDrivers('the page list says when each page was last edited', function (string $driver) {
    $db = adminSite($driver);
    Settings::set($db, 'timezone', 'Europe/Zagreb');
    $id = createPage($db, 'en', 'about', 'About');
    $db->query('UPDATE pages SET updated_at = ? WHERE id = ?', ['2026-01-05 08:00:00', $id]);

    assertContains('5 Jan 2026, 09:00', dispatch('/admin/pages')->body, 'the date in winter time');
    assertEquals('UTC', Dates::zone(installedSite(['en' => 'English'], $driver)), 'a site with no zone set reads UTC');
});
