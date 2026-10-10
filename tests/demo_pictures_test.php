<?php

use App\Core\Blocks;
use App\Modules\Demo\DemoPictures;
use App\Modules\Demo\DemoSite;
use App\Modules\Design\Composition;
use App\Modules\Media\MediaMeta;

// The demo's pictures, its documents and the copy a new block starts with (PLAN.md D-213):
// pictures from demo_images/, described in both of the demo's languages from its credits.json,
// placed where the pages name them, behind a section too; documents in the page's language;
// and the home page's hero following its character. The files are stored by a stand-in here:
// making their sizes is the media tests' business, and takes seconds the suite need not spend.
// CHANGED DELIBERATELY with D-213: Atelier Lumen's six pictures from install/demo/ gave way to
// The Printworks', and the test kept its rules.

testBothDrivers('the demo puts its pictures and documents where its pages name them, described in both its languages', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $files = [];
    $store = static function (string $file, string $name) use ($db, &$files): int {
        $files[] = $file;
        $id = storedPicture($db, $name, ['card' => ['width' => 400, 'height' => 300, 'formats' => ['webp']]]);
        // A document is a file, not a picture (D-126): a file field keeps only a file.
        if (str_ends_with($name, '.pdf')) {
            $db->query("UPDATE media SET kind = 'file', mime = 'application/pdf' WHERE id = ?", [$id]);
        }

        return $id;
    };
    DemoSite::seed($db, $registry, 'en', $store);

    $named = DemoPictures::named(DemoSite::pages('en'));
    assertEquals(count($named['pictures']) + count($named['files']), count($files), 'every picture and document named stored, once');
    foreach ($files as $file) {
        assertTrue(is_file($file) && str_contains($file, '/demo_images/'), 'from demo_images/: ' . $file);
    }
    $catalogue = DemoPictures::catalogue();
    foreach ($named['pictures'] as $name) {
        assertTrue(isset($catalogue[$name]), 'a picture named that credits.json does not describe: ' . $name);
    }
    $id = static fn (string $name): int => (int) ($db->one('SELECT id FROM media WHERE filename = ?', [$name])['id'] ?? 0);
    $page = static fn (string $slug): int => (int) ($db->one("SELECT id FROM pages WHERE slug = ? AND locale = 'en'", [$slug])['id'] ?? 0);
    $blocks = static function (int $page) use ($db): array {
        $found = [];
        foreach (App\Modules\Pages\PageBlocks::stored($db, $page) as $block) {
            $found[$block['type']][] = $block;
        }

        return $found;
    };
    $onHome = $blocks($page(''));
    assertEquals($id('abandoned-workshop-hall.webp'), $onHome['hero'][0]['content']['image'] ?? null, 'the hero');
    assertEquals(
        [$id('great-wave-woodblock.webp'), $id('potter-hands-wheel.webp'), $id('mugs-wooden-table.webp')],
        array_column($onHome['cards'][0]['content']['items'] ?? [], 'image'),
        'the three cards of Three things we do',
    );
    // A picture behind a section, named the same way (D-213).
    $quote = array_values(array_filter(App\Modules\Pages\Sections::forPage($db, $page('')), static fn (array $section): bool => ($section['style']['surface'] ?? '') === 'image'));
    assertEquals($id('kraft-cardboard.webp'), $quote[0]['style']['image'] ?? null, 'the kraft paper behind the visitor\'s word');
    // A document in the page's language.
    $downloads = $blocks($page('hire'))['downloads'][0]['content']['items'] ?? [];
    assertEquals([$id('technical-rider-en.pdf'), $id('hire-prices-en.pdf')], array_column($downloads, 'file'), 'the hire documents, in English');

    // The home hero follows its character since the owner's decision, stored as '' since
    // D-191: drawn in Soft's layout, and changing with the character.
    assertEquals('', $onHome['hero'][0]['layout'] ?? null, 'the hero follows the character');
    assertContains('block-hero layout-' . Composition::layout($registry, DemoSite::CHARACTER, 'hero'), dispatch('/')->body, 'and is drawn in its layout');

    // And the site is the place the pages are about (D-177, D-213).
    assertEquals('The Printworks', App\Core\Settings::text($db, 'site_name'), 'the demo names the site');

    $alts = MediaMeta::forPicture($db, $id('abandoned-workshop-hall.webp'));
    assertEquals($catalogue['space/abandoned-workshop-hall']['en'], $alts['en']['alt'] ?? null, 'English alt, from credits.json');
    assertEquals($catalogue['space/abandoned-workshop-hall']['hr'], $alts['hr']['alt'] ?? null, 'Croatian alt, for the translation');
    assertTrue(isset($alts['hr'], $alts['en']), 'an alt in each language');
    assertTrue($alts['hr']['alt'] !== '' && $alts['en']['alt'] !== $alts['hr']['alt'], 'two languages, two words');
});

test('the demo made without its pictures leaves their fields empty', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $home = (int) ($db->one("SELECT id FROM pages WHERE slug = '' AND locale = 'en'")['id'] ?? 0);
    foreach (App\Modules\Pages\PageBlocks::stored($db, $home) as $block) {
        $content = (string) json_encode($block['content']);
        assertTrue(!str_contains($content, 'demo-picture:') && !str_contains($content, 'demo-file:'), $block['type'] . ' kept a picture or document name instead of an id or nothing');
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
