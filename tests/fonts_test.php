<?php

use App\Modules\Design\Derived;
use App\Modules\Design\Fonts;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;
use App\Modules\Design\Vocabulary\Decisions;

/*
 * THE FONT LIBRARY AND THE TWO FAMILIES (PLAN.md D-185). That every file carries č ć đ š ž and
 * their capitals is measured in a browser (scenario 65), which can read a woff2; this holds the
 * library to its files, licences and pairings.
 */

test('the decision offers exactly the library\'s families', function () {
    assertEquals(array_keys(Fonts::ALL), Decisions::FONTS, 'Decisions::FONTS and Fonts::ALL');
    foreach (Fonts::ALL as $id => $font) {
        assertTrue(str_starts_with($font['stack'], '"' . $font['name'] . '", '), "{$id}: its stack names it first");
    }
});

test('every family has its files in both subsets, and the Open Font License beside them', function () {
    $root = dirname(__DIR__) . '/public/assets/fonts';
    foreach (Fonts::ALL as $id => $font) {
        foreach (array_keys(Fonts::files($id)) as $file) {
            foreach (['latin', 'latin-ext'] as $subset) {
                $path = "{$root}/{$id}/{$id}-{$subset}-{$file}.woff2";
                assertTrue(is_file($path) && filesize($path) > 1000, "{$id}: {$subset} {$file}");
                assertEquals('wOF2', (string) file_get_contents($path, false, null, 0, 4), "{$id}: {$subset} {$file} is a woff2");
            }
        }
        assertContains('SIL Open Font License', (string) @file_get_contents("{$root}/{$id}/OFL.txt"), "{$id}: its licence");
    }
    // And no family on disk that the library does not offer.
    $dirs = array_values(array_filter(scandir($root) ?: [], static fn (string $d): bool => $d[0] !== '.' && is_dir("{$root}/{$d}")));
    sort($dirs);
    $ids = array_keys(Fonts::ALL);
    sort($ids);
    assertEquals($ids, $dirs, 'families on disk');
});

test('a page declares and preloads only its two families', function () {
    $css = Typography::fontFaces(['bodoni-moda', 'figtree'], '../assets/fonts');
    assertEquals(4, substr_count($css, '@font-face'), 'two variable families, two subsets each');
    assertContains('font-family: "Bodoni Moda";', $css, 'the heading\'s');
    assertContains('font-weight: 400 900;', $css, 'a variable family\'s own range');
    assertTrue(!str_contains($css, 'Inter'), 'nothing else');
    assertContains('font-display: swap;', $css, 'drawn at once in the fallback');

    // A static family: a face per weight there is a file for.
    assertEquals(4, substr_count(Typography::fontFaces(['libre-caslon-text'], '../assets/fonts'), '@font-face'), 'two weights, two subsets');
    assertEquals(['libre-caslon-text/libre-caslon-text-latin-700.woff2', 'inter/inter-latin-wght.woff2'], Typography::preloads('libre-caslon-text', 'inter', 650), 'the nearest weight there is a file for, and the text\'s');
    assertEquals(['inter/inter-latin-wght.woff2'], Typography::preloads('inter', 'inter', 650), 'one family, one file');
});

test('a heading weight a family has no file for is the nearest it has', function () {
    assertEquals(400, Fonts::weight('instrument-serif', 700), 'a family of one weight');
    assertEquals(700, Fonts::weight('libre-caslon-text', 650), 'the nearer of two');
    assertEquals(700, Fonts::weight('cormorant-garamond', 900), 'the top of a variable range');
    assertEquals('400', Derived::from(['heading_font' => 'young-serif', 'heading_weight' => '800'] + Presets::get('minimal'))['heading']['weight'], 'and so the page');
});

test('a pairing sets its two families and draws as the pairing drew', function () {
    foreach (Typography::PAIRINGS as $name => $pairing) {
        $drawn = Derived::from(Tokens::resolve(Typography::pairing($name)));
        assertEquals(Fonts::stack($pairing['heading']), $drawn['font']['heading'], "{$name}: the heading family");
        assertEquals(Fonts::stack($pairing['body']), $drawn['font']['body'], "{$name}: the text's");
        assertEquals($pairing['heading_weight'], $drawn['heading']['weight'], "{$name}: weight");
        assertEquals($pairing['tracking'], $drawn['heading']['tracking'], "{$name}: letter spacing");
        assertEquals($pairing['transform'], $drawn['heading']['transform'], "{$name}: capitals");
        assertEquals($pairing['leading_body'], $drawn['leading']['body'], "{$name}: line height");
        assertEquals($pairing['leading_heading'], $drawn['leading']['heading'], "{$name}: a heading's line height");
    }
});

// A design stored before D-185 named one of six pairings: it keeps the faces it had.
test('a pairing stored before the two families is read as them', function () {
    // As a row stored then held it: the owner's keys only.
    $read = Tokens::validate(['typography' => 'grotesk']);
    assertEquals([], $read['errors'], 'errors');
    assertEquals('space-grotesk', $read['decisions']['heading_font'], 'the heading family');
    assertEquals('1.55', $read['decisions']['line_height'], 'and the line height the pairing had');
    $mine = Tokens::validate(['typography' => 'grotesk', 'heading_font' => 'syne']);
    assertEquals('syne', $mine['decisions']['heading_font'], 'a family already named wins');
});

testBothDrivers('a visitor\'s page preloads the two families\' first files', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['action' => 'save']));
    $body = dispatch('/about')->body;
    preg_match_all('~<link rel="preload" href="([^"]+)" as="font" type="font/woff2" crossorigin>~', $body, $links);
    assertEquals(2, count($links[1]), 'two');
    assertContains('playfair-display-latin-wght.woff2', implode(' ', $links[1]), 'the heading\'s');
    assertContains('source-serif-4-latin-wght.woff2', implode(' ', $links[1]), 'the text\'s');
});
