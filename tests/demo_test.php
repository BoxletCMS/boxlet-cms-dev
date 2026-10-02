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
            $used['layout'][] = $block['type'] . '/' . $block['layout'];
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
testBothDrivers('the home page is the mockup page, its sections holding only what they set', function (string $driver) {
    $db = installedSite(['hr' => 'Hrvatski'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    DemoSite::seed($db, $registry, 'hr');

    $home = (int) ($db->one("SELECT id FROM pages WHERE slug = '' AND locale = 'hr'")['id'] ?? 0);
    $sections = array_values(Sections::forPage($db, $home));
    $seeded = DemoSite::pages('hr')[0]['sections'];
    assertEquals(count($seeded), count($sections), 'sections');
    foreach ($seeded as $at => $section) {
        assertEquals(SectionStyle::normalize($section['style']), $sections[$at]['style'], "section {$at} stored more than it set");
        assertEquals($section['layout'], $sections[$at]['layout'], "section {$at}'s layout");
    }

    $body = dispatch('/')->body;
    assertContains('Prostori koji izgledaju kao da ste ih oduvijek imali', $body, 'the mockup\'s heading, word for word');
    assertTrue(preg_match('~<section class="[^"]*surface-tinted[^"]*pad-t-120 pad-b-120"[^>]*data-anim="fade"~', $body) === 1, 'Uvod: tinted, 120 px, fading in');
    assertContains('id="usluge"', $body, 'Usluge answers to its anchor');
    assertContains('<a href="/#usluge">Što radimo</a>', $body, 'and the menu leads to it');
    assertContains('class="section-cols cols-wide-left', $body, 'Iskustvo: two columns, the wide one left');
    assertContains('surface-contrast', $body, 'Kontakt on the contrast surface');
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

// README 1.6: the home page in the other language, so a translation is always there.
testBothDrivers('the demo\'s home page is translated, with its own words and its own anchor', function (string $driver) {
    $db = installedSite(['hr' => 'Hrvatski'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'hr');

    $translation = $db->one("SELECT id, translation_status FROM pages WHERE locale = 'en' AND slug = ''");
    assertTrue($translation !== null, 'no English home page');
    $body = dispatch('/en/')->body;
    assertContains('Spaces that feel as if they had always been yours', $body, 'its words are English');
    assertContains('id="services"', $body, 'its anchor is its own');
    assertContains('href="/en/#services"', $body, 'and its menu leads to it');
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
