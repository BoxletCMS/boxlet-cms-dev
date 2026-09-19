<?php

// What the site itself says to a visitor (PLAN.md O-19, D-044): lang/site/, one file per
// language, each complete, with the page's language first, then the main one, then English.

test('every language Boxlet speaks to visitors has every word English has', function () {
    $dir = dirname(__DIR__) . '/lang/site';
    $english = require $dir . '/en.php';
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $strings = require $file;
        $missing = array_keys(array_diff_key($english, is_array($strings) ? $strings : []));
        assertEquals([], $missing, basename($file) . ' lacks words');
        assertTrue(isset(App\Modules\Languages\Locales::known()[basename($file, '.php')]), basename($file) . ' is not a language code');
    }
});

testBothDrivers('the not-found page speaks the language of its address, else the main one', function (string $driver) {
    $db = installedSite(['hr' => 'Hrvatski', 'de' => 'Deutsch', 'sq' => 'Shqip'], $driver);

    assertContains('Stranica nije pronađena', dispatch('/nigdje')->body, 'the main language, Croatian');
    assertContains('Seite nicht gefunden', dispatch('/de/nirgendwo')->body, 'German');
    // Albanian is on the site but Boxlet has no words for it: the main language's, not English.
    assertContains('Stranica nije pronađena', dispatch('/sq/askund')->body, 'a language Boxlet does not speak');
});
