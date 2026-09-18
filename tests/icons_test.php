<?php

// The admin's icon sprite (PLAN.md D-037). A browser draws nothing from a sprite that is not
// well-formed XML, and nothing for a name the sprite lacks, and neither says why on screen —
// the first build shipped with a double hyphen in its licence comment and every icon in the
// bar was an empty square.

test('the icon sprite is well-formed and holds every icon the code asks for', function () {
    $root = dirname(__DIR__);
    $sprite = new DOMDocument();
    assertTrue(@$sprite->load($root . '/public/assets/vendor/icons.svg'), 'icons.svg is not well-formed XML');

    $have = [];
    foreach ($sprite->getElementsByTagName('symbol') as $symbol) {
        $have[] = $symbol->getAttribute('id');
    }

    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            preg_match_all("~\\bicon\\('([a-z0-9-]+)'\\)~", (string) file_get_contents($file->getPathname()), $found);
            $used = array_merge($used, $found[1]);
        }
    }
    assertTrue($used !== [], 'no icon() call found: the scan is looking in the wrong place');

    $missing = array_values(array_diff(array_unique($used), array_map(static fn (string $id): string => substr($id, 2), $have)));
    assertEquals([], $missing, 'icons used but not in the sprite (add them to tools/icons/build.php)');
});
