<?php

use App\Core\Blocks;
use App\Modules\Demo\DemoPictures;
use App\Modules\Demo\DemoSite;
use App\Modules\Design\Composition;
use App\Modules\Media\MediaMeta;

// The demo's pictures and the copy a new block starts with (PLAN.md D-176): the six pictures
// placed where the owner put them, described in both of the demo's languages, and the home
// page's hero following its character. The pictures are stored by a stand-in here: making
// their sizes is the media tests' business, and takes seconds the suite need not spend.

testBothDrivers('the demo puts its six pictures where the owner placed them, described in both its languages', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $files = [];
    $store = static function (string $file, string $name) use ($db, &$files): int {
        $files[] = $file;

        return storedPicture($db, basename($name, '.jpg'), ['card' => ['width' => 400, 'height' => 300, 'formats' => ['jpg']]]);
    };
    DemoSite::seed($db, $registry, 'en', $store);

    assertEquals(count(DemoPictures::PICTURES), count($files), 'every picture stored');
    foreach ($files as $file) {
        assertTrue(is_file($file) && str_contains($file, '/install/demo/'), 'from the package, not docs/: ' . $file);
    }
    $id = static fn (string $name): int => (int) ($db->one('SELECT id FROM media WHERE filename = ?', [$name])['id'] ?? 0);
    $home = (int) ($db->one("SELECT id FROM pages WHERE slug = '' AND locale = 'en'")['id'] ?? 0);
    $about = (int) ($db->one("SELECT id FROM pages WHERE slug = 'about'")['id'] ?? 0);
    $blocks = static function (int $page) use ($db): array {
        $found = [];
        foreach (App\Modules\Pages\PageBlocks::stored($db, $page) as $block) {
            $found[$block['type']][] = $block;
        }

        return $found;
    };
    $onHome = $blocks($home);
    assertEquals($id('hero-living-room'), $onHome['hero'][0]['content']['image'] ?? null, 'the hero');
    assertEquals(
        [$id('card-homes'), $id('card-offices'), $id('card-shops')],
        array_column($onHome['cards'][0]['content']['items'] ?? [], 'image'),
        'the three cards of What we do',
    );
    assertEquals($id('process-plan'), $onHome['image_text'][0]['content']['image'] ?? null, 'From sketch to keys');
    assertEquals($id('about-studio'), $blocks($about)['image_text'][0]['content']['image'] ?? null, 'the About page');

    // The home hero follows its character since the owner's decision, stored as '' since
    // D-191 (CHANGED DELIBERATELY): drawn split under Soft, and changing with the character.
    assertEquals('', $onHome['hero'][0]['layout'] ?? null, 'the hero follows the character');
    assertContains('block-hero layout-' . Composition::layout($registry, DemoSite::CHARACTER, 'hero'), dispatch('/')->body, 'and is drawn in its layout');

    // And the site is the studio the pages are about (D-177).
    assertEquals('Atelier Lumen', App\Core\Settings::text($db, 'site_name'), 'the demo names the site');

    $alts = MediaMeta::forPicture($db, $id('hero-living-room'));
    assertEquals('Living room with an arched window', $alts['en']['alt'] ?? null, 'English alt');
    assertEquals('Dnevni boravak s lučnim prozorom', $alts['hr']['alt'] ?? null, 'Croatian alt, for the translation');
});

test('the demo made without its pictures leaves their fields empty', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $home = (int) ($db->one("SELECT id FROM pages WHERE slug = '' AND locale = 'en'")['id'] ?? 0);
    foreach (App\Modules\Pages\PageBlocks::stored($db, $home) as $block) {
        assertTrue(!str_contains((string) json_encode($block['content']), 'demo-picture:'), $block['type'] . ' kept a picture name instead of an id or nothing');
    }
});

test('a new block starts with its samples in the page\'s language, English where it has none, and no link', function () {
    $registry = blockRegistry();
    $hr = $registry->sampled('hero', static fn (string $key): string => site_t($key, 'hr', 'samples'));
    assertEquals('Rečenica koja kaže što je ovo', $hr['heading'] ?? null, 'Croatian');
    assertEquals(['label' => '', 'url' => ''], array_intersect_key((array) ($hr['cta'] ?? []), ['label' => 1, 'url' => 1]), 'a link stays empty: its words without an address would be an error');

    $de = $registry->sampled('text', static fn (string $key): string => site_t($key, 'de', 'samples'));
    assertEquals('<p>A paragraph or two, set the way this site sets writing.</p>', $de['body'] ?? null, 'English where German has no samples, rich text as a paragraph');

    $cards = $registry->sampled('cards', static fn (string $key): string => site_t($key, 'en', 'samples'));
    assertEquals(3, count($cards['items'] ?? []), 'three cards, as fresh() gives');
    assertEquals('One of the three', $cards['items'][2]['heading'] ?? null, 'each item says what it is');
    assertEquals(null, $cards['items'][0]['image'] ?? null, 'and no picture');
});
