<?php

/**
 * BOXLETCMS.ORG, UPDATES THAT CANNOT BREAK A SITE (PLAN.md D-276, the owner, 2026-10-15: "mislim
 * da smo zaboravili na boxletcms.org istaknuti … nedestruktivni web update"). A section on the
 * home page after the one on AI translation, with the Updates screen after a real update and its
 * Roll back; a question in the home page's list; and, on Boxlet vs WordPress, AI translation in
 * the Languages card (its Updates card already says what this says). Every fact checked in the
 * code: Package::SKIPPED, Upgrade, Releases (the digest), UpdatesController.
 *
 *   php tools/site/content-4.php <site root> <site address> <folder of the new pictures> [--dry]
 */

$newPictures = [
    'updates-rollback.png' => 'Boxlet\'s Updates screen after an update: this site runs Boxlet v0.1.0, updated from v0.1.0-rc.16, with a button to roll back to it',
];

require __DIR__ . '/kit.php';

$section = ['image_text', [
    'heading' => 'Updates that can\'t break your site',
    'body' => '<p>Updating is one press in the admin, and it never touches what you made. Boxlet backs up the whole site first, checks the new version against the checksum GitHub publishes, and replaces only its own code: your pages, pictures, settings, designs and your own lines in .htaccess stay exactly as they are. While it runs, visitors see a short note in the site\'s language, and it goes in steps that suit cheap shared hosting.</p><p>If something is wrong afterwards, <strong>Roll back</strong> puts the previous version back, together with the site as it was before the update.</p>',
    'image' => 'pic:updates-rollback.png',
    'image_fit' => 'contain',
    'link' => ['label' => 'Updating', 'url' => 'site:docs/updating'],
], 'image-left', []];

$question = [
    'What happens when I update?',
    '<p>Nothing you made is touched. Boxlet backs up the whole site, checks the release against the checksum GitHub publishes, puts its new code in place and makes its database changes. If anything is wrong afterwards, one press of Roll back puts it all back as it was. <a href="site:docs/updating">Updating</a></p>',
];

// ---- the home page: the section after AI translation's, and the question after AI's ----------------
$page('', static function (array $blocks, array $sections) use ($section, $question, $has, $sectionOf, $insert, $reference): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'accordion' && !in_array($question[0], array_column($block['content']['items'], 'question'), true)) {
            $items = $block['content']['items'];
            $after = array_search('Can AI translate my site?', array_column($items, 'question'), true);
            $answer = (string) preg_replace_callback('~(?<=href=")site:([a-z0-9/-]*)(?=")~', static fn (array $m): string => $reference($m[1]), $question[1]);
            array_splice($items, $after === false ? count($items) : $after + 1, 0, [['question' => $question[0], 'answer' => $answer]]);
            $blocks[$i]['content']['items'] = $items;
            $said[] = 'a question about updating';
        }
    }
    if (!$has($blocks, $section[1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'Translate a whole site with AI');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['blocks' => [$section]]]);
            $said[] = 'the section on updates';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Boxlet vs WordPress: AI translation in the Languages card ----------------------------------------
$page('boxlet-vs-wordpress', static function (array $blocks, array $sections): array {
    $said = [];
    $was = '<p>WordPress: a translation plugin. Boxlet: built in, with an address and menus for each language and hreflang written for you.</p>';
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'cards' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['heading'] === 'Languages' && $item['body'] === $was) {
                $blocks[$i]['content']['items'][$n]['body'] = '<p>WordPress: a translation plugin. Boxlet: built in, with an address and menus for each language and hreflang written for you, and AI translation with your own key.</p>';
                $said[] = 'AI translation in the Languages card';
            }
        }
    }

    return [$blocks, $sections, $said];
});

$finish();
