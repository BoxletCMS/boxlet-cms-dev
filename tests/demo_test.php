<?php

use App\Core\Blocks;
use App\Modules\Demo\DemoSite;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaReference;
use App\Modules\Pages\SectionRender;
use App\Modules\Pages\Sections;

// The demo site (PLAN.md D-167, README 1.6): the mockup's page as the home, four pages behind
// it, the home translated, and one unlisted page of every block. It is the visual regression
// fixture, so between its pages it still has to cover everything.

testBothDrivers('the demo site publishes pages covering every block, layout and section style', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    assertEquals(count(DemoSite::pages('en')), DemoSite::seed($db, $registry, 'en'), 'pages created');
    assertEquals(0, (int) ($db->one("SELECT COUNT(*) AS n FROM pages WHERE status <> 'published'")['n'] ?? -1), 'unpublished demo pages');

    // AS DRAWN: a section stores only what it sets since D-165, so what the demo shows is
    // the stored values over the character's — which is what has to cover every value.
    $character = Composition::active($db);
    $used = ['layout' => [], 'animation' => [], 'v_align' => []] + array_fill_keys(array_keys(SectionStyle::OPTIONS), []);
    foreach ($db->all('SELECT id FROM pages') as $page) {
        $blocks = App\Modules\Pages\Page::blocks($db, (int) $page['id']);
        foreach ($blocks as $block) {
            // As drawn: '' follows the character (D-191).
            $used['layout'][] = $block['type'] . '/' . ($block['layout'] !== '' ? $block['layout'] : Composition::layout($registry, $character, $block['type']));
        }
        foreach (Sections::group(Sections::forPage($db, (int) $page['id']), $blocks) as $group) {
            $style = SectionRender::style($character, $group['section']['style'], $group['blocks']);
            foreach (array_keys(SectionStyle::OPTIONS) as $key) {
                // Where the content sits counts only in a band taller than it.
                if ($key !== 'v_align' || (int) $style['min_height'] > 0) {
                    $used[$key][] = (string) $style[$key];
                }
            }
        }
    }
    foreach ($registry->types() as $type) {
        foreach ($registry->get($type)['layouts'] as $layout) {
            assertTrue(in_array("{$type}/{$layout}", $used['layout'], true), "the demo never uses {$type} with layout {$layout}");
        }
    }
    foreach (SectionStyle::OPTIONS as $key => $values) {
        foreach ($values as $value) {
            assertTrue(in_array($value, $used[$key], true), "the demo never draws {$key}: {$value}");
        }
    }

    // The seed references no picture at all. An id for a picture nobody uploaded is a
    // dangling reference, and the first photograph that happened to take that number was
    // silently adopted by the page holding it — measured, and then not deletable, because
    // a page "used" it.
    $referenced = [];
    foreach ($db->all('SELECT block_type, content_json FROM page_blocks') as $row) {
        $content = json_decode((string) $row['content_json'], true);
        foreach (MediaReference::idsIn($registry, (string) $row['block_type'], is_array($content) ? $content : []) as $id) {
            $referenced[] = $row['block_type'] . ' = ' . $id;
        }
    }
    assertEquals([], $referenced, 'the demo seed references media ids');

    foreach (['/', '/about', '/services', '/how-we-work', '/contact', '/blocks', '/hr/'] as $path) {
        assertEquals(200, dispatch($path)->status, $path);
    }
});

// The home page is the mockup's, and a section stores what the page names and nothing else.
// In English, which Boxlet ships in (README 1.6), word for word.
testBothDrivers('the home page is the mockup page, its sections holding only what they set', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    DemoSite::seed($db, $registry, 'en');

    $home = (int) ($db->one("SELECT id FROM pages WHERE slug = '' AND locale = 'en'")['id'] ?? 0);
    $sections = array_values(Sections::forPage($db, $home));
    $seeded = DemoSite::pages('en')[0]['sections'];
    assertEquals(count($seeded), count($sections), 'sections');
    foreach ($seeded as $at => $section) {
        // What the page names, less what the demo's character composes anyway (the owner's
        // review of D-170): such a value stored would be one more section "styled by hand".
        $named = SectionStyle::normalize($section['style']);
        $composed = Composition::section(DemoSite::CHARACTER, array_column($section['blocks'], 0));
        foreach ($sections[$at]['style'] as $key => $value) {
            $character = array_key_exists($key, $composed) ? (string) $composed[$key] : null;
            if ($value === '') {
                assertTrue($named[$key] === '' || (string) $named[$key] === $character, "section {$at} lost its {$key}");
            } else {
                assertEquals($named[$key], $value, "section {$at} stored a {$key} it did not name");
                assertTrue((string) $value !== $character, "section {$at} stored the character's own {$key}");
            }
        }
        assertEquals($section['layout'], $sections[$at]['layout'], "section {$at}'s layout");
    }
    assertEquals(DemoSite::CHARACTER, Composition::active($db), 'the demo is drawn with its character');

    $body = dispatch('/')->body;
    foreach (['Spaces that feel like they were always yours', 'From a single room to the whole flat.', 'Windows, shelving and the customer\'s path.', 'Fifteen years and more than two hundred spaces. We take on only a few projects at a time, so each one gets our full attention.', 'Get in touch and we&#039;ll plan the first step.'] as $words) {
        assertContains($words, $body, 'README 1.6, verbatim');
    }
    assertTrue(preg_match('~<section class="[^"]*surface-tinted[^"]*pad-t-120 pad-b-120"[^>]*data-anim="fade"~', $body) === 1, 'Intro: tinted, 120 px, fading in');
    assertContains('id="services"', $body, 'What we do answers to its anchor');
    assertContains('<a href="/#services">What we do</a>', $body, 'and the menu leads to it');
    assertContains('class="section-cols cols-wide-left', $body, 'Experience: two columns, the wide one left');
    assertContains('surface-contrast', $body, 'Contact on the contrast surface');
});

// A fresh demo is not "styled by hand" (the owner's review of D-170): loading a character
// asked about thirty sections. What stays stored is only where a page means to differ from
// Soft, and each is named here — the mockup's home in both languages, and the showroom's
// sections, which exist to show a value no character composes.
testBothDrivers('the demo styles by hand only the sections that mean to differ from its character', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $deliberate = [
        'en /#0' => 'Intro: 120 px above and below, fading in (README 1.6)',
        'en /#4' => 'Contact: the contrast surface (README 1.6)',
        'en /blocks#0' => 'a hero centred, rising in, tall',
        'en /blocks#1' => 'the gradient surface, half a window high, content in the middle',
        'en /blocks#2' => 'a picture behind, the slant edge',
        'en /blocks#3' => 'the wide width',
        'en /blocks#4' => 'the full width, zooming in, a screen high',
        'en /blocks#5' => 'the line edge',
        'en /blocks#6' => 'the contrast surface, the curve edge',
        'en /blocks#7' => 'a band taller than its content, content at the top',
        'en /blocks#9' => 'the same, content at the bottom',
        'en /blocks#26' => 'the narrow width',
        'hr /#0' => 'Intro, translated',
        'hr /#4' => 'Contact, translated',
    ];
    $styled = [];
    $at = [];
    foreach ($db->all('SELECT s.id, s.style_json, p.locale, p.slug FROM page_sections s JOIN pages p ON p.id = s.page_id ORDER BY p.id, s.sort') as $row) {
        $page = $row['locale'] . ' /' . $row['slug'];
        $at[$page] = ($at[$page] ?? -1) + 1;
        $style = SectionStyle::normalize(json_decode((string) $row['style_json'], true));
        // A section's own style only: a block's options are the owner's and Apply keeps them,
        // so they no longer make a section "styled by hand" (CHANGED DELIBERATELY, D-191).
        if (SectionStyle::overridden($style)) {
            $styled[] = $page . '#' . $at[$page];
        }
    }
    assertEquals(array_keys($deliberate), $styled, 'styled by hand');
    assertEquals(count($deliberate), Composition::styledByHand($db), 'and what the Apply question counts');
});

// The demo links to its own pages the way an owner's site does: by reference, so renaming
// a page cannot break it (PLAN.md D-034).
testBothDrivers('the demo links its pages by reference, and the links lead there', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $stored = implode("\n", array_column($db->all('SELECT content_json FROM page_blocks'), 'content_json'));
    assertTrue(!str_contains($stored, 'demo:'), 'a demo: marker was stored');
    $contact = (int) ($db->one("SELECT id FROM pages WHERE slug = 'contact'")['id'] ?? 0);
    assertContains('"url":"page:' . $contact . '"', $stored, 'no reference to the contact page');
    assertContains('<a class="button" href="/contact">Book a consultation</a>', dispatch('/')->body, 'the hero\'s button does not lead to its page');

    $db->query("UPDATE pages SET slug = 'write-to-us' WHERE id = ?", [$contact]);
    assertContains('href="/write-to-us"', dispatch('/')->body, 'the home page does not follow the renamed page');
});

// README 1.6: the home page in Croatian, the mockup's own words, so a translation is always there.
testBothDrivers('the demo\'s home page is translated, with its own words and its own anchor', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $translation = $db->one("SELECT id FROM pages WHERE locale = 'hr' AND slug = ''");
    assertTrue($translation !== null, 'no Croatian home page');
    $body = dispatch('/hr/')->body;
    assertContains('Prostori koji izgledaju kao da ste ih oduvijek imali', $body, 'its words are the mockup\'s');
    assertContains('id="usluge"', $body, 'its anchor is its own');
    assertContains('href="/hr/#usluge"', $body, 'and its menu leads to it');
});

test('the demo is never added to a site that already has pages', function () {
    $db = installedSite(['en' => 'English']);
    createPage($db, 'en', 'real', 'Real content');

    assertThrows(fn () => DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en'), 'already has pages');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'pages');
});

test('installing with the demo option adds the demo site', function () {
    freshDatabase('sqlite');
    $installer = installer();
    installGet($installer);
    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');
    assertAdvanced(installPost($installer, ['driver' => 'sqlite', 'path' => tmpPath('test.sqlite')]), 'database step');
    $password = 'correct horse battery staple';
    assertAdvanced(installPost($installer, ['email' => 'owner@example.com', 'password' => $password, 'password_confirm' => $password]), 'admin step');
    installPost($installer, ['name' => 'Demo', 'locale' => 'en', 'timezone' => 'UTC', 'demo' => '1']);

    $db = new \App\Core\Db('sqlite', 'sqlite:' . tmpPath('test.sqlite'));
    // Its pages, and the home page's translation.
    assertEquals(count(DemoSite::pages('en')) + 1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'demo pages');
});

testBothDrivers('the demo site has navigation, so its header is drawn at all', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    // An empty header is deliberately not drawn (D-032), so a demo without a menu is a demo
    // with no navigation anywhere — and an Appearance screen with no header to show (D-057).
    $body = dispatch('/')->body;
    assertContains('<header class="', $body, 'the demo site draws no header');
    foreach (['What we do', 'About', 'Services', 'How we work', 'Contact'] as $title) {
        assertContains('>' . $title . '<', $body, 'in the menu: ' . $title);
    }
    assertTrue(!str_contains($body, '>Every block<'), 'the showroom is in the menu');
});
