<?php

use App\Core\Blocks;
use App\Modules\Demo\DemoSite;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaReference;

// The demo site is the visual regression fixture: it has to cover everything.

testBothDrivers('the demo site publishes pages covering every block, layout and section style', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    assertEquals(count(DemoSite::pages()), DemoSite::seed($db, $registry, 'en'), 'pages created');
    assertEquals(0, (int) ($db->one("SELECT COUNT(*) AS n FROM pages WHERE status <> 'published'")['n'] ?? -1), 'unpublished demo pages');

    $used = ['layout' => []] + array_fill_keys(array_keys(SectionStyle::OPTIONS), []);
    foreach ($db->all('SELECT block_type, style_json, layout FROM page_blocks') as $row) {
        $used['layout'][] = $row['block_type'] . '/' . $row['layout'];
        $style = json_decode((string) $row['style_json'], true);
        foreach (SectionStyle::OPTIONS as $key => $values) {
            $used[$key][] = is_array($style) ? ($style[$key] ?? '') : '';
        }
    }
    foreach ($registry->types() as $type) {
        foreach ($registry->get($type)['layouts'] as $layout) {
            assertTrue(in_array("{$type}/{$layout}", $used['layout'], true), "the demo never uses {$type} with layout {$layout}");
        }
    }
    foreach (SectionStyle::OPTIONS as $key => $values) {
        foreach ($values as $value) {
            assertTrue(in_array($value, $used[$key], true), "the demo never uses {$key}: {$value}");
        }
    }

    // The seed references no picture at all. An id for a picture nobody uploaded is a
    // dangling reference, and the first photograph that happened to take that number was
    // silently adopted by the page holding it — measured, and then not deletable, because
    // a page "used" it. Photographs arrive with D-022 and are set explicitly.
    $referenced = [];
    foreach ($db->all('SELECT block_type, content_json FROM page_blocks') as $row) {
        $content = json_decode((string) $row['content_json'], true);
        foreach (MediaReference::fields($registry)[(string) $row['block_type']] ?? [] as $field) {
            $value = is_array($content) ? ($content[$field] ?? null) : null;
            if ($value !== null) {
                $referenced[] = $row['block_type'] . '.' . $field . ' = ' . var_export($value, true);
            }
        }
    }
    assertEquals([], $referenced, 'the demo seed references media ids');

    foreach (['/', '/about', '/services', '/style-guide'] as $path) {
        assertEquals(200, dispatch($path)->status, $path);
    }
});

// The demo links to its own pages the way an owner's site does: by reference, so renaming
// a page cannot break it (PLAN.md D-034). A typed '/services' would still render; only the
// stored form shows which one the seed wrote.
testBothDrivers('the demo links its pages by reference, and the links lead there', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    DemoSite::seed($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), 'en');

    $stored = implode("\n", array_column($db->all('SELECT content_json FROM page_blocks'), 'content_json'));
    assertTrue(!str_contains($stored, 'demo:'), 'a demo: marker was stored');
    assertTrue(!str_contains($stored, '"\/services"') && !str_contains($stored, '"\/about"'), 'a demo link was stored as a typed path');

    $services = (int) ($db->one("SELECT id FROM pages WHERE slug = 'services'")['id'] ?? 0);
    assertContains('"url":"page:' . $services . '"', $stored, 'no reference to the services page');

    $db->query("UPDATE pages SET slug = 'what-we-do' WHERE id = ?", [$services]);
    assertContains('href="/what-we-do"', dispatch('/')->body, 'the home page does not follow the renamed page');
    assertContains('<a href="/">', dispatch('/style-guide')->body, 'the style guide\'s links to home do not lead there');
});

// The seed stores what the editor would store. A block that names only its surface takes
// the character's composition for everything else — otherwise the unnamed keys fall to the
// closed-set defaults, which the character did not choose, and the editor rightly shows the
// section as hand-tuned. That is how all 21 demo panels came to be open.
testBothDrivers('a block that seeds only its surface matches the composition everywhere else', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    DemoSite::seed($db, $registry, 'en');

    $character = App\Modules\Design\Composition::active($db);
    $stored = [];
    foreach ($db->all('SELECT block_type, style_json FROM page_blocks ORDER BY id') as $row) {
        $stored[] = [
            'type' => (string) $row['block_type'],
            'style' => SectionStyle::normalize(json_decode((string) $row['style_json'], true)),
        ];
    }

    // The seed definitions, in the same order the seeder writes them.
    $seeded = [];
    foreach (DemoSite::pages() as $page) {
        foreach ($page['blocks'] as [$type, , $style]) {
            $seeded[] = ['type' => $type, 'style' => $style];
        }
    }
    assertEquals(count($seeded), count($stored), 'blocks seeded and blocks stored');

    $checked = 0;
    foreach ($seeded as $index => $block) {
        if (array_keys($block['style']) !== ['surface']) {
            continue;
        }
        $checked++;
        $expected = App\Modules\Design\Composition::style($character, $block['type']);
        $expected['surface'] = $block['style']['surface'];
        assertEquals($expected, $stored[$index]['style'], "block {$index} ({$block['type']}) seeded with only a surface");
    }
    assertTrue($checked > 0, 'no demo block seeds only a surface, so this proves nothing');
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
    assertEquals(count(DemoSite::pages()), (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'demo pages');
});
