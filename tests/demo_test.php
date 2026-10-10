<?php

use App\Core\Blocks;
use App\Modules\Demo\DemoSite;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaReference;
use App\Modules\Pages\SectionRender;
use App\Modules\Pages\Sections;

// The demo site (PLAN.md D-213): The Printworks, ten pages and three beneath them, Home and
// Visit translated, and one unlisted page of every block. It is the visual regression fixture,
// so between its pages it still has to cover everything. CHANGED DELIBERATELY with D-213: the
// tests below kept their rules and took The Printworks' pages, words and anchors where they
// named Atelier Lumen's.

testBothDrivers('the demo site publishes pages covering every block, layout and section style', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    assertEquals(count(DemoSite::pages('en')), DemoSite::seed($db, $registry, 'en'), 'pages created');
    assertEquals(0, (int) ($db->one("SELECT COUNT(*) AS n FROM pages WHERE status <> 'published'")['n'] ?? -1), 'unpublished demo pages');

    // AS DRAWN: a section stores only what it sets since D-165, so what the demo shows is
    // the stored values over the character's — which is what has to cover every value. The
    // character its pages are written against (Soft): since D-216 the demo is shown with
    // Couture, which draws the values its sections leave to Soft its own way (CHANGED
    // DELIBERATELY, the owner's choice of Couture; it measured the active character before).
    $character = DemoSite::CHARACTER;
    $used = ['layout' => [], 'animation' => [], 'v_align' => []] + array_fill_keys(array_keys(SectionStyle::OPTIONS), []);
    foreach ($db->all('SELECT id FROM pages') as $page) {
        $blocks = App\Modules\Pages\PageBlocks::stored($db, (int) $page['id']);
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

    foreach (['/', '/whats-on', '/exhibitions', '/exhibitions/floating-world', '/workshops', '/workshops/linocut', '/cafe-bookshop', '/membership', '/hire', '/about', '/journal', '/journal/restoring-the-press-hall', '/visit', '/blocks', '/hr/', '/hr/posjet'] as $path) {
        assertEquals(200, dispatch($path)->status, $path);
    }
});

// The home page's sections store what the page names and nothing else, and its blocks follow
// the character (D-194). In English, which Boxlet ships in, word for word.
testBothDrivers('the home page\'s sections hold only what they set, and its blocks follow the character', function (string $driver) {
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
    // Written against Soft, shown with Couture and a centred header (the owner, D-216).
    assertEquals('couture', Composition::active($db), 'the demo is drawn with Couture');
    assertEquals('centred', App\Modules\Design\Design::load($db)['header_arrangement'] ?? null, 'under a centred header');

    // Every block follows the character but the four numbers and this week's events (D-194, D-213).
    $own = [];
    foreach (App\Modules\Pages\PageBlocks::stored($db, $home) as $block) {
        if ($block['layout'] !== '') {
            $own[] = $block['type'] . '/' . $block['layout'];
        }
    }
    assertEquals(['stats/four', 'cards/list'], $own, 'blocks with a layout of their own');

    $body = dispatch('/')->body;
    foreach (['A printing works, reopened for everyone.', 'Three things we do', 'From press hall to public hall', 'I came for a coffee, stayed for a workshop, and now I have my own studio key.', 'Northgate Arts Fund'] as $words) {
        assertContains($words, $body, 'the home page\'s words');
    }
    assertTrue(preg_match('~<section class="[^"]*surface-contrast[^"]*"[^>]*data-anim="fade"~', $body) === 1, 'In numbers: the contrast surface, fading in');
    assertContains('id="program"', $body, 'Three things we do answers to its anchor');
    assertContains('class="section-cols cols-wide-left', $body, 'This week: two columns, the wide one left');
    assertContains('surface-image', $body, 'A visitor\'s word, on a picture');
});

// A fresh demo is not "styled by hand" (the owner's review of D-170): loading a character
// asked about thirty sections. What stays stored is only where a page means to differ from
// Soft, and each is named here — The Printworks' own in both languages where translated, and
// the showroom's sections, which exist to show a value no character composes.
testBothDrivers('the demo styles by hand only the sections that mean to differ from its character', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $deliberate = [
        'en /#1' => 'In numbers: the contrast surface, fading in',
        'en /#6' => 'A visitor\'s word: on the kraft paper',
        'en /whats-on#3' => 'the newsletter: the contrast surface',
        'en /workshops#0' => 'the hero centred',
        'en /membership#0' => 'the hero centred, 20px above and none below (the owner, D-216)',
        'en /membership#2' => 'what membership pays for: the contrast surface',
        'en /restoring-the-press-hall#0' => 'the article: narrow',
        'en /restoring-the-press-hall#2' => 'its quote: narrow',
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
        'hr /#1' => 'In numbers, translated',
        'hr /#6' => 'A visitor\'s word, translated',
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

// The owner's review of the Printworks in Couture (D-216): Membership's hero close to the
// header and to the levels under it, and tall; Exhibitions' picture under the light shade.
testBothDrivers('the demo has the owner\'s spacing on Membership and the light shade on Exhibitions', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');
    $first = static function (string $slug) use ($db): array {
        $id = (int) ($db->one("SELECT id FROM pages WHERE slug = ? AND locale = 'en'", [$slug])['id'] ?? 0);

        return array_values(Sections::forPage($db, $id))[0];
    };

    $membership = SectionStyle::normalize($first('membership')['style']);
    assertEquals(['20', '0'], [(string) $membership['pad_top'], (string) $membership['pad_bottom']], 'Membership\'s hero: 20 above, none below');
    assertContains('pad-t-20', dispatch('/membership')->body, 'drawn so');
    assertContains('class="hero height-tall', dispatch('/membership')->body, 'Membership\'s hero tall, not Couture\'s whole screen');
    assertTrue(preg_match('~class="hero [^"]*veil-light~', dispatch('/exhibitions')->body) === 1, 'Exhibitions\' hero under the light shade');
});

// The demo links to its own pages the way an owner's site does: by reference, so renaming
// a page cannot break it (PLAN.md D-034).
testBothDrivers('the demo links its pages by reference, and the links lead there', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $stored = implode("\n", array_column($db->all('SELECT content_json FROM page_blocks'), 'content_json'));
    assertTrue(!str_contains($stored, 'demo:'), 'a demo: marker was stored');
    $program = (int) ($db->one("SELECT id FROM pages WHERE slug = 'whats-on'")['id'] ?? 0);
    assertContains('"url":"page:' . $program . '"', $stored, 'no reference to the programme page');
    assertContains('<a class="button" href="/whats-on">What&#039;s on</a>', dispatch('/')->body, 'the hero\'s button does not lead to its page');

    $db->query("UPDATE pages SET slug = 'programme' WHERE id = ?", [$program]);
    assertContains('href="/programme"', dispatch('/')->body, 'the home page does not follow the renamed page');
});

// Home and Visit in Croatian (the owner, D-213), so a translation is always there: their own
// words, Visit's form a Croatian form, and a menu over what was translated.
testBothDrivers('the demo\'s home and visit pages are translated, with their own words and form', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    assertEquals(2, (int) ($db->one("SELECT COUNT(*) AS n FROM pages WHERE locale = 'hr'")['n'] ?? -1), 'Croatian pages');
    $home = dispatch('/hr/')->body;
    assertContains('Tiskara, ponovno otvorena za sve.', $home, 'the home page\'s own words');
    assertContains('id="program"', $home, 'its anchor');
    assertContains('href="/hr/posjet"', $home, 'and its menu leads to the translated Visit');

    $visit = dispatch('/hr/posjet')->body;
    assertContains('Posjetite nas', $visit, 'Visit\'s own words');
    $forms = $db->all("SELECT name FROM forms WHERE locale = 'hr' ORDER BY name");
    assertContains('Kontakt', implode(',', array_column($forms, 'name')), 'the Croatian forms');
    assertContains('Pošalji', $visit, 'Visit\'s form, in Croatian');
});

test('the demo is never added to a site that already has pages', function () {
    $db = installedSite(['en' => 'English']);
    createPage($db, 'en', 'real', 'Real content');

    assertThrows(fn () => DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en'), 'already has pages');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'pages');
});

test('installing with the demo option adds the demo site', function () {
    freshDatabase('sqlite');
    $taken = [];
    $installer = installer(true, true, demoStandIn($taken));
    installGet($installer);
    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');
    assertAdvanced(installPost($installer, ['driver' => 'sqlite', 'path' => tmpPath('test.sqlite')]), 'database step');
    $password = 'correct horse battery staple';
    assertAdvanced(installPost($installer, ['email' => 'owner@example.com', 'password' => $password, 'password_confirm' => $password]), 'admin step');
    installPost($installer, ['name' => 'Demo', 'locale' => 'en', 'timezone' => 'UTC', 'demo' => '1']);
    // Its pictures next, in steps (D-214), by the stand-in: making their sizes is the media
    // tests' business, and the real thing was measured on the browser copy (01-install).
    installPost($installer, []);
    assertEquals(count(App\Modules\Install\InstallDemo::pictures('en')), count($taken), 'every picture taken');

    $db = new \App\Core\Db('sqlite', 'sqlite:' . tmpPath('test.sqlite'));
    // Its pages, and the two translations (D-213).
    assertEquals(count(DemoSite::pages('en')) + count(DemoSite::TRANSLATED), (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'demo pages');
});

testBothDrivers('the demo site has navigation, so its header is drawn at all', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    // An empty header is deliberately not drawn (D-032), so a demo without a menu is a demo
    // with no navigation anywhere — and an Appearance screen with no header to show (D-057).
    $body = dispatch('/')->body;
    assertContains('<header class="', $body, 'the demo site draws no header');
    foreach (['What&#039;s on', 'Exhibitions', 'Workshops', 'Café &amp; Bookshop', 'Membership', 'Visit'] as $title) {
        assertContains('>' . $title . '<', $body, 'in the menu: ' . $title);
    }
    // The rest in the footer: Boxlet has no automatic "More" (D-213).
    $footer = substr($body, (int) strpos($body, '<footer'));
    foreach (['Hire the space', 'About', 'Journal'] as $title) {
        assertContains('>' . $title . '<', $footer, 'in the footer: ' . $title);
    }
    assertContains('14 Foundry Lane', $footer, 'the address in the footer');
    assertTrue(!str_contains($body, '>Every block<'), 'the showroom is in the menu');
});

// Three pages stand beneath others, and their addresses say so (D-213).
testBothDrivers('the demo\'s pages beneath others nest under them', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $parent = static fn (string $slug): string => (string) ($db->one('SELECT p.slug FROM pages c JOIN pages p ON p.id = c.parent_id WHERE c.slug = ?', [$slug])['slug'] ?? '');
    assertEquals('exhibitions', $parent('floating-world'), 'Floating World');
    assertEquals('workshops', $parent('linocut'), 'Linocut');
    assertEquals('journal', $parent('restoring-the-press-hall'), 'the article');
    assertContains('href="/exhibitions/floating-world"', dispatch('/exhibitions')->body, 'the past exhibition\'s link');
});

// Four forms, each with what it asks (D-213): the contact form as Boxlet makes one, the others
// with their own fields, each on the page that names it.
testBothDrivers('the demo\'s forms ask what their pages need', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $fields = [];
    foreach ($db->all("SELECT name, fields_json FROM forms WHERE locale = 'en'") as $row) {
        $fields[(string) $row['name']] = array_column((array) json_decode((string) $row['fields_json'], true), 'type', 'key');
    }
    assertEquals(['name' => 'text', 'email' => 'email', 'message' => 'textarea'], $fields['Contact'] ?? null, 'Contact');
    assertEquals(['email' => 'email'], $fields['Newsletter'] ?? null, 'Newsletter');
    assertEquals(['name' => 'text', 'email' => 'email', 'workshop' => 'select', 'message' => 'textarea'], $fields['Workshop sign-up'] ?? null, 'Workshop sign-up');
    assertEquals('select', $fields['Hire enquiry']['room'] ?? null, 'Hire enquiry: a room to choose');
    $workshops = dispatch('/workshops')->body;
    assertContains('<option>Screen printing</option>', $workshops, 'the workshop sign-up, on Workshops');
    assertContains('type="email"', dispatch('/whats-on')->body, 'the newsletter, on What\'s on');
});

// A new demo never opens on a past programme (D-213): its days are counted from the install.
test('the demo\'s events fall after the day it is installed', function () {
    $db = installedSite(['en' => 'English']);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $tomorrow = strtotime('+1 days', strtotime('today'));
    $day = date('l', $tomorrow) . ' ' . date('j F', $tomorrow);
    assertContains($day . ', 19:00', dispatch('/whats-on')->body, 'the first talk, tomorrow');
    assertContains($day . ', 19:00', dispatch('/')->body, 'and on the home page');
});
