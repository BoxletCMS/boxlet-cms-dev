<?php

// What a screen reader needs from the markup itself, on every screen the owner works in
// (PLAN.md D-181, the a11y pass of phase 6): an id is one element's, and every label and
// aria-labelledby names an element that is there. The browser suite's a11yProblems() checks
// the drawn page besides (names of controls, pictures' alt); this is the half that needs no
// browser, so it runs on every commit.

testBothDrivers('no admin screen uses an id twice, or names one that is not there', function (string $driver) {
    $db = adminSite($driver);
    $page = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Welcome']],
        ['type' => 'cards', 'content' => ['heading' => 'Three', 'items' => [['heading' => 'One'], ['heading' => 'Two']]]],
    ]);
    $menu = App\Modules\Menus\Menu::create($db, 'en', 'Header');
    App\Modules\Menus\Menu::addItem($db, $menu, null, $page, null, 'About');

    foreach ([
        '/admin', '/admin/pages', '/admin/pages/new', '/admin/pages/' . $page, '/admin/pages/' . $page . '/form',
        '/admin/media', '/admin/appearance', '/admin/navigation', '/admin/menus/' . $menu, '/admin/settings',
    ] as $path) {
        $body = dispatch($path)->body;
        // Inside a <template> is a pattern, cloned with its ids made unique when it is used.
        $drawn = (string) preg_replace('~<template\b.*?</template>~s', '', $body);
        preg_match_all('~\sid="([^"]+)"~', $drawn, $ids);
        $twice = array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1));
        assertEquals([], $twice, "{$path}: ids used twice");

        $known = array_flip($ids[1]);
        preg_match_all('~<label\b[^>]*\sfor="([^"]+)"~', $drawn, $fors);
        preg_match_all('~\saria-(?:labelledby|describedby)="([^"]+)"~', $drawn, $named);
        $missing = [];
        foreach (array_merge($fors[1], ...array_map(static fn (string $list): array => preg_split('~\s+~', trim($list)) ?: [], $named[1])) as $id) {
            if (!isset($known[$id])) {
                $missing[] = $id;
            }
        }
        assertEquals([], array_values(array_unique($missing)), "{$path}: labels naming no element");
    }
});
