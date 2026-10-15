<?php

/**
 * BOXLETCMS.ORG FOR v0.1.0 (PLAN.md D-274, the owner, 2026-10-15): AI translation on the home
 * page and the Languages page, with the demo as the example the owner gave (14 pages into
 * Croatian with Claude Opus 5.5, its menu, pictures and forms included, about 30 cents); copying
 * into a language and what comes with it; forms that keep messages only when told to (D-269),
 * where the Forms page said every message is kept; the changelog's v0.1.0; the Download page's
 * words for a release. Run once, through the site's own code, as content-1.php was:
 *
 *   php tools/site/content-2.php <site root> <site address> <folder of the new pictures> [--dry]
 *
 * With --dry every change is made in one transaction, said, and rolled back: no picture is
 * uploaded (an existing one stands in), nothing is kept, the sitemap and the kept pages are not
 * touched. The rehearsal on the live database itself, read and put back.
 *
 * A page with a draft is left and said. An edit finds its block by the words it replaces, and a
 * new section by its heading, so a second run changes nothing.
 */

$newPictures = [
    'ai-translated.png' => 'Boxlet\'s translation screen: twelve of the demo\'s pages translated into Croatian, each a draft',
];

require __DIR__ . '/kit.php';
require __DIR__ . '/content-2-words.php';

// ---- the home page: the Languages card, a section, a question ---------------------------------
$page('', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert, $reference): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'cards') {
            foreach ($block['content']['items'] as $n => $item) {
                if ($item['heading'] === 'Languages from day one') {
                    $blocks[$i]['content']['items'][$n]['heading'] = $words['home_card'][0];
                    $blocks[$i]['content']['items'][$n]['body'] = $words['home_card'][1];
                    $said[] = 'the Languages card';
                }
            }
        }
        if ($block['type'] === 'accordion' && !in_array($words['home_question'][0], array_column($block['content']['items'], 'question'), true)) {
            $items = $block['content']['items'];
            $after = array_search('How is it different from WordPress?', array_column($items, 'question'), true);
            $answer = (string) preg_replace_callback('~(?<=href=")site:([a-z0-9/-]*)(?=")~', static fn (array $m): string => $reference($m[1]), $words['home_question'][1]);
            array_splice($items, $after === false ? count($items) : $after + 1, 0, [['question' => $words['home_question'][0], 'answer' => $answer]]);
            $blocks[$i]['content']['items'] = $items;
            $said[] = 'a question about AI';
        }
    }
    if (!$has($blocks, $words['home_section'][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, "An admin that's nice to be in");
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['blocks' => [$words['home_section']]]]);
            $said[] = 'the AI section';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Features: the Multilingual card ------------------------------------------------------------
$page('features', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'cards' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['heading'] === 'Multilingual from day one' && $item['body'] !== $words['features_card']) {
                $blocks[$i]['content']['items'][$n]['body'] = $words['features_card'];
                $said[] = 'the Multilingual card';
            }
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Languages: AI translation after "Translate a page in one press"; what follows ------------------
$page('features/languages', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if (($block['content']['heading'] ?? null) === 'What follows by itself' && $block['content']['body'] !== $words['follows']) {
            $blocks[$i]['content']['body'] = $words['follows'];
            $said[] = 'what follows by itself';
        }
    }
    if (!$has($blocks, $words['languages'][0]['blocks'][0][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'Translate a page in one press');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, $words['languages']);
            $said[] = count($words['languages']) . ' sections on AI translation';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Forms: what is kept is the owner's choice now (D-269) --------------------------------------
$page('features/forms', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        $heading = $block['content']['heading'] ?? null;
        if ($block['type'] === 'gallery' && $heading === 'Messages are kept in the admin') {
            $blocks[$i]['content']['heading'] = $words['forms_gallery'][0];
            $blocks[$i]['content']['items'][0]['caption'] = $words['forms_gallery'][1];
            $said[] = 'the messages gallery';
        } elseif ($heading === 'Every message is kept') {
            $blocks[$i]['content']['heading'] = $words['forms_kept'][0];
            $blocks[$i]['content']['body'] = $words['forms_kept'][1];
            $said[] = 'what is kept';
        } elseif ($heading === 'Making a form' && !str_contains((string) $block['content']['body'], 'copied into another language')) {
            $blocks[$i]['content']['body'] = $words['forms_making'];
            $said[] = 'making a form';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- the changelog's v0.1.0, first ---------------------------------------------------------------
$page('changelog', static function (array $blocks, array $sections) use ($words, $has, $insert): array {
    if ($has($blocks, $words['changelog'][1]['heading'])) {
        return [$blocks, $sections, []];
    }
    [$blocks, $sections] = $insert($blocks, $sections, 0, [['blocks' => [$words['changelog']]]]);

    return [$blocks, $sections, ['v0.1.0 added']];
});

// ---- Download: a release, not a candidate -------------------------------------------------------
$page('get', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        $body = $block['content']['body'] ?? null;
        if (is_string($body) && str_contains($body, 'a release candidate for v0.1')) {
            $blocks[$i]['content']['body'] = str_replace('a release candidate for v0.1', $words['get'], $body);
            $said[] = 'the version line';
        }
    }

    return [$blocks, $sections, $said];
});

$finish();
