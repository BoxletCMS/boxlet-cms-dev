<?php

use App\Core\Settings;

// The settings table through one accessor (PLAN.md D-028). Six places had grown their own
// copy of these three lines before this existed, which is how the encoding flags in one of
// them quietly stop matching the others.
//
// migratedDatabase(), not installedSite(): the installer writes site_name and timezone, and
// a test for "a missing key returns the default" cannot use a database that already has them.

testBothDrivers('a setting survives the round trip, and a second write replaces it', function (string $driver) {
    $db = migratedDatabase($driver);

    Settings::set($db, 'site_name', 'Prva');
    assertEquals('Prva', Settings::get($db, 'site_name'), 'what was written did not come back');

    Settings::set($db, 'site_name', 'Druga');
    assertEquals('Druga', Settings::get($db, 'site_name'), 'the second write did not replace the first');

    // `key` is the primary key, so a writer that inserted without deleting would throw
    // rather than duplicate — this asserts the row count for the reader who "simplifies"
    // set() into a single INSERT one day.
    $row = $db->one('SELECT COUNT(*) AS n FROM settings WHERE `key` = ?', ['site_name']);
    if ($row === null) {
        fail('counting the rows returned nothing at all');
    }
    assertEquals(1, (int) $row['n'], 'rows for one key');
});

testBothDrivers('a key that is not there, and a row that is not JSON, both read as the default', function (string $driver) {
    $db = migratedDatabase($driver);

    assertEquals(null, Settings::get($db, 'never_written'), 'a missing key');
    assertEquals('fallback', Settings::get($db, 'never_written', 'fallback'), 'a missing key with a default');

    // A settings table edited by hand is a real thing on a shared host. A screen that
    // fatals on one bad row is worse than one that shows its default.
    $db->query('INSERT INTO settings (`key`, value_json) VALUES (?, ?)', ['broken', '{not json']);
    assertEquals('fallback', Settings::get($db, 'broken', 'fallback'), 'a row that does not decode');
    assertEquals('', Settings::text($db, 'broken'), 'text() over a row that does not decode');
});

testBothDrivers('text() and mediaId() refuse what is stored in the wrong shape', function (string $driver) {
    $db = migratedDatabase($driver);

    Settings::set($db, 'a_number', 42);
    Settings::set($db, 'a_list', ['not', 'a', 'string']);
    assertEquals('', Settings::text($db, 'a_number'), 'a number is not text');
    assertEquals('none', Settings::text($db, 'a_list', 'none'), 'a list is not text');

    Settings::set($db, 'picture', 7);
    Settings::set($db, 'picture_as_text', '7');
    Settings::set($db, 'picture_none', 0);
    Settings::set($db, 'picture_empty', '');
    Settings::set($db, 'picture_words', 'seven');
    assertEquals(7, Settings::mediaId($db, 'picture'), 'a stored id');
    assertEquals(7, Settings::mediaId($db, 'picture_as_text'), 'an id a form posted as text');
    assertEquals(null, Settings::mediaId($db, 'picture_none'), 'zero means none chosen');
    assertEquals(null, Settings::mediaId($db, 'picture_empty'), 'an empty picker means none chosen');
    assertEquals(null, Settings::mediaId($db, 'picture_words'), 'a word is not an id');
    assertEquals(null, Settings::mediaId($db, 'picture_missing'), 'a key that was never written');
});

// many() is the one with SQL worth proving on both engines: IN (...) with a generated
// placeholder list, and `key` in backticks, which SPEC §5.0 requires to work either side.
testBothDrivers('many() answers for every key asked for, present or not, in one query', function (string $driver) {
    $db = migratedDatabase($driver);
    Settings::set($db, 'site_name', 'Studio');
    Settings::set($db, 'timezone', 'Europe/Zagreb');

    $found = Settings::many($db, ['site_name', 'timezone', 'site_logo'], '');

    assertEquals(['site_name' => 'Studio', 'timezone' => 'Europe/Zagreb', 'site_logo' => ''], $found, 'the set that came back');
    assertEquals([], Settings::many($db, []), 'asking for nothing');
});

testBothDrivers('a value with Croatian letters is stored readable, not as escapes', function (string $driver) {
    $db = migratedDatabase($driver);
    Settings::set($db, 'site_name', 'Čvarci i đevrek — Šibenik');

    assertEquals('Čvarci i đevrek — Šibenik', Settings::get($db, 'site_name'), 'it did not come back the same');

    // The stored bytes, not the decoded value: UNESCAPED_UNICODE is the claim, and a
    // database someone opens by hand is the reason for it.
    $row = $db->one('SELECT value_json FROM settings WHERE `key` = ?', ['site_name']);
    if ($row === null) {
        fail('the row is not there');
    }
    assertContains('Čvarci', (string) $row['value_json'], 'the stored JSON escaped the letters');
});
