<?php

use App\Modules\Pages\Slug;

// Page addresses: generation, format, reserved codes and uniqueness per locale.

$titles = [
    'Hello World!' => 'hello-world',
    'Čudna šuma, đak i žaba' => 'cudna-suma-dak-i-zaba',
    'Über Straße & Café' => 'uber-strasse-cafe',
    '  --Spaced -- out--  ' => 'spaced-out',
    '日本語' => '',
];
foreach ($titles as $title => $expected) {
    test("a slug is generated from the title: {$title}", function () use ($title, $expected) {
        assertEquals($expected, Slug::fromTitle($title), 'slug');
    });
}

testBothDrivers('slug format, reserved language codes and system paths', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);

    assertEquals(null, Slug::problem($db, 'en', 'about-us', null), 'about-us');
    assertEquals(null, Slug::problem($db, 'en', '', null), 'the home page');
    assertEquals(t('pages.slug.reserved_language', ['slug' => 'de', 'language' => 'Deutsch']), Slug::problem($db, 'en', 'de', null), 'de, not enabled');
    assertEquals(t('pages.slug.reserved_language', ['slug' => 'hr', 'language' => 'Hrvatski']), Slug::problem($db, 'en', 'hr', null), 'hr, enabled');
    assertEquals(t('pages.slug.reserved_system', ['slug' => 'admin']), Slug::problem($db, 'en', 'admin', null), 'admin');
    foreach (['About', 'a--b', '-a', 'a/b', 'č', str_repeat('a', 101)] as $bad) {
        assertEquals(t('pages.slug.invalid', ['max' => 100]), Slug::problem($db, 'en', $bad, null), $bad);
    }
});

testBothDrivers('a slug is unique within a locale and may repeat across locales', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $about = createPage($db, 'en', 'about', 'About');
    createPage($db, 'en', '', 'Home');

    assertEquals(t('pages.slug.taken', ['slug' => 'about']), Slug::problem($db, 'en', 'about', null), 'same locale');
    assertEquals(null, Slug::problem($db, 'hr', 'about', null), 'other locale');
    assertEquals(null, Slug::problem($db, 'en', 'about', $about), 'the page keeping its own slug');
    assertEquals(t('pages.slug.home_taken'), Slug::problem($db, 'en', '', null), 'second home page');
});

testBothDrivers('generated slugs get a number when taken or reserved', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', 'about', 'About');

    assertEquals('about-2', Slug::unique($db, 'en', 'About', null), 'taken');
    assertEquals('de-2', Slug::unique($db, 'en', 'DE', null), 'reserved');
    assertEquals('page', Slug::unique($db, 'en', '日本語', null), 'nothing usable in the title');
});
