<?php

// Layer 3: a block's layout lives in page_blocks.layout, validated against its definition.

function layoutOf(\App\Core\Db $db, int $blockId): string
{
    return (string) ($db->one('SELECT layout FROM page_blocks WHERE id = ?', [$blockId])['layout'] ?? '');
}

testBothDrivers('a declared layout is saved; one the block does not declare falls back to the default', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Hi']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);
    $save = static fn (string $layout) => adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => 'about',
        'blocks' => [['id' => (string) $blockId, 'type' => 'hero', 'heading' => 'Hi', 'layout' => $layout]],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $save('split'));
    assertEquals('split', layoutOf($db, $blockId), 'declared layout');
    assertContains('class="block block-hero layout-split ', dispatch('/about')->body, 'rendered layout');

    assertRedirectedTo("/admin/pages/{$id}", $save('image-left'));
    assertEquals('center', layoutOf($db, $blockId), "image_text's layout on a hero");
});

testBothDrivers('a stored layout the definition no longer declares renders as the default', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Hi']]]);
    $db->query('UPDATE page_blocks SET layout = ?', ['removed-in-a-later-version']);

    assertContains('class="block block-hero layout-center ', dispatch('/about')->body, 'front end');
    assertContains('<option value="center" selected>', dispatch("/admin/pages/{$id}")->body, 'editor');
});

test('new blocks start in their default layout', function () {
    $db = adminSite('sqlite');
    adminPost('/admin/pages', ['title' => 'Landing', 'locale' => 'en', 'template' => templateId($db, 'landing')]);

    $layouts = array_map(static fn (array $b): string => $b['layout'], blocksWithStyle($db));
    assertEquals(['center', 'image-left', 'single'], $layouts, 'layouts of hero, image_text, text');
});
