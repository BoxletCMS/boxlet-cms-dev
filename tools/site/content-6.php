<?php

/**
 * BOXLETCMS.ORG FOR v0.2 (PLAN.md D-300; the owner, 2026-11-08: "update sadržaja na boxlet.org sa
 * novim funkcionalnostima i change logom"). A new feature page, features/ai-apps, on AI apps
 * through MCP, with its card on Features, its section and question on the home page and the way
 * on from The admin; Scripts & cookies and the box that asks on Privacy and on the page about a
 * site without a cookie banner; bulk upload and the grid on Pictures; webhooks, Brevo and
 * Mailgun on Forms; five scripts, not four; the AI paths Settings has now; Why's "a release
 * candidate"; updating from a ZIP; the changelog's v0.2.0. The words, and what each was checked
 * against, are in content-6-words.php. The Download page's version is version.php's, run after.
 *
 *   php tools/site/content-6.php <site root> <site address> <folder of the new pictures> [--dry]
 *
 * As content-2.php: a page with a draft is left and said; each edit finds its block by the words
 * it replaces and each new section by its heading, so a second run changes nothing.
 */

use App\Modules\Admin\Activity;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageSeo;
use App\Support\RichText;

$newPictures = [
    'ai-access.png' => 'Boxlet\'s AI access settings: a key called Claude Code just made, which may read and write drafts, pictures and design sets, with the line that connects Claude Code',
    'scripts-cookies.png' => 'Boxlet\'s Scripts & cookies: Google Analytics code pasted in, recognised as a statistics script with its cookies filled in',
    'consent-choose.png' => 'The demo site\'s cookie box, drawn from its design: necessary always on, statistics off until chosen, with Reject all, Accept all and Save my choice',
    'consent-box.png' => 'A visitor\'s first page on the demo with a statistics script added: the box that asks, with Reject all as large as Accept all',
    'bulk-upload.png' => 'Six pictures dropped on Boxlet\'s media library at once: two done, one sending at 19%, three waiting, each with its own bar',
    'media-grid.png' => 'Boxlet\'s media library as a grid: each picture with its tick, its ⋯ menu, its size and where it is used',
    'form-webhook.png' => 'A Boxlet form sending each message to a Zapier webhook address, with its signing secret and a button to send a test',
];

require __DIR__ . '/kit.php';
$words = require __DIR__ . '/content-6-words.php';

$links = static fn (string $html): string => RichText::sanitize((string) preg_replace_callback('~(?<=href=")site:([a-z0-9/-]*)(?=")~', static fn (array $m): string => $reference($m[1]), $html));
/** A block's richtext body changed from what it was ($from, exactly or as a part) to $to. */
$swap = static function (array &$blocks, string $from, string $to, string $field = 'body'): bool {
    foreach ($blocks as $i => $block) {
        $now = $block['content'][$field] ?? null;
        if (is_string($now) && str_contains($now, $from)) {
            $blocks[$i]['content'][$field] = str_replace($from, $to, $now);
            return true;
        }
    }
    return false;
};

// ---- the new page first: the others link to it ------------------------------------------------
$features = $pageAt('features') ?? throw new RuntimeException('No page features');
if ($pageAt('features/ai-apps') === null) {
    $new = $words['ai_page'];
    $id = Page::create($db, $registry, $locale, $new['title'], 'ai-apps', null, []);
    [$sections, $blocks] = $rows($new['sections'], 0);
    Page::update($db, $registry, $id, [
        'title' => $new['title'],
        'slug' => 'ai-apps',
        'parent_id' => (int) $features['id'],
        'status' => 'draft',
        'seo_json' => PageSeo::json(['title' => '', 'description' => $new['description'], 'noindex' => false]),
    ], $blocks, $sections);
    Page::setStatus($db, $id, true);
    Activity::record($db, 'page', 'saved', $id, $new['title']);
    echo "/features/ai-apps: made and published, " . count($sections) . " sections\n";
} else {
    echo "/features/ai-apps: there already\n";
}

// ---- home ------------------------------------------------------------------------------------------
$page('', static function (array $blocks, array $sections) use ($words, $links, $has, $sectionOf, $insert): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'stats') {
            foreach ($block['content']['items'] as $n => $item) {
                if ($item['value'] === '4' && $item['label'] === 'small scripts, at most') {
                    $blocks[$i]['content']['items'][$n]['value'] = '5';
                    $said[] = '5 scripts';
                }
            }
        }
        if ($block['type'] === 'accordion') {
            $items = $block['content']['items'];
            foreach ($items as $n => $item) {
                if ($item['question'] === 'Does my site need a cookie banner?' && !str_contains($item['answer'], 'Google Analytics')) {
                    $items[$n]['answer'] = $links($words['home_cookie_answer']);
                    $said[] = 'the cookie banner answer';
                }
            }
            if (!in_array($words['home_question'][0], array_column($items, 'question'), true)) {
                $after = array_search('Can AI translate my site?', array_column($items, 'question'), true);
                array_splice($items, $after === false ? count($items) : $after + 1, 0, [['question' => $words['home_question'][0], 'answer' => $links($words['home_question'][1])]]);
                $said[] = 'a question about AI apps';
            }
            $blocks[$i]['content']['items'] = $items;
        }
    }
    if (!$has($blocks, $words['home_section'][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'Translate a whole site with AI');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['style' => [], 'blocks' => [$words['home_section']]]]);
            $said[] = 'the section on AI apps';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Features: the card -------------------------------------------------------------------------------
$page('features', static function (array $blocks, array $sections) use ($words, $registry, $resolve): array {
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'cards' && !in_array($words['features_card']['heading'], array_column($block['content']['items'], 'heading'), true)) {
            $content = $block['content'];
            $content['items'][] = $words['features_card'];
            $blocks[$i]['content'] = $registry->normalize('cards', $resolve($registry->get('cards')['fields'], $content));

            return [$blocks, $sections, ['the AI apps card']];
        }
    }

    return [$blocks, $sections, []];
});

// ---- The admin: tabs in Settings, and the way on to AI apps -----------------------------------------
$page('features/the-admin', static function (array $blocks, array $sections) use ($words, $swap, $resolve, $registry): array {
    $said = [];
    if ($swap($blocks, '<p>Search pages, pictures and settings from anywhere.</p>', $words['admin_search'])) {
        $said[] = 'settings in tabs';
    }
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'cta' && $block['content']['heading'] === 'Try it on your next small site') {
            $blocks[$i]['content'] = $registry->normalize('cta', $resolve($registry->get('cta')['fields'], $words['admin_next']));
            $blocks[$i]['layout'] = $registry->own('cta', 'beside');
            $said[] = 'the way on to AI apps';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Privacy -----------------------------------------------------------------------------------------
$page('features/privacy', static function (array $blocks, array $sections) use ($words, $swap, $has, $sectionOf, $insert): array {
    $said = [];
    if ($swap($blocks, '<li>Four small scripts at most, each only on a page that needs it.</li>', '<li>Five small scripts at most, each only on a page that needs it.</li>')) {
        $said[] = 'five scripts';
    }
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'accordion' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['answer'] === '<p>Switch Boxlet\'s statistics off in Settings.</p>') {
                $blocks[$i]['content']['items'][$n]['answer'] = $words['privacy_other_tool'];
                $said[] = 'another analytics tool';
            }
        }
    }
    if (!$has($blocks, $words['privacy_sections'][0]['blocks'][0][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'What else stays quiet');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, $words['privacy_sections']);
            $said[] = 'Scripts & cookies and the box';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- A website without a cookie banner -------------------------------------------------------------------
$page('website-without-cookie-banner', static function (array $blocks, array $sections) use ($words, $swap, $has, $sectionOf, $insert): array {
    $said = [];
    if ($swap($blocks, '<p>Settings writes a paragraph about the statistics', '<p>The Statistics screen writes a paragraph about the statistics')) {
        $said[] = 'where the privacy paragraph is';
    }
    if (!$has($blocks, $words['banner_section'][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'Fonts and everything else');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['style' => [], 'blocks' => [$words['banner_section']]]]);
            $said[] = 'when a script sets cookies';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Pictures ------------------------------------------------------------------------------------------
$page('features/pictures', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert): array {
    if ($has($blocks, $words['pictures_sections'][0]['blocks'][0][1]['heading'])) {
        return [$blocks, $sections, []];
    }
    $at = $sectionOf($blocks, $sections, 'Focal point and crop');
    if ($at === null) {
        return [$blocks, $sections, []];
    }
    [$blocks, $sections] = $insert($blocks, $sections, $at, $words['pictures_sections']);

    return [$blocks, $sections, ['bulk upload and the grid']];
});

// ---- Forms ---------------------------------------------------------------------------------------------
$page('features/forms', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if (($block['content']['heading'] ?? null) === 'SMTP or Resend') {
            [$blocks[$i]['content']['heading'], $blocks[$i]['content']['body']] = $words['forms_mail'];
            $said[] = 'Brevo and Mailgun';
        }
    }
    if (!$has($blocks, $words['forms_section'][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'When a message is sent');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['style' => [], 'blocks' => [$words['forms_section']]]]);
            $said[] = 'webhooks';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Languages, Why, Updating, Download, Boxlet vs WordPress ----------------------------------------------
$page('features/languages', static function (array $blocks, array $sections) use ($words, $swap, $links): array {
    $said = [];
    foreach ($words['languages_ai'] as $from => $to) {
        $done = array_filter($blocks, static fn (array $b): bool => str_contains((string) ($b['content']['body'] ?? ''), 'An AI app connected to the site'));
        if (str_contains($to, 'AI apps') && $done !== []) {
            continue;
        }
        if ($swap($blocks, $from, str_contains($to, 'site:') ? $links($to) : $to)) {
            $said[] = str_contains($to, 'AI apps') ? 'AI apps translate the same way' : 'Settings → AI → Translation';
        }
    }

    return [$blocks, $sections, $said];
});
$page('why', static function (array $blocks, array $sections) use ($words): array {
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'accordion' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['question'] === 'Is it ready?' && str_contains($item['answer'], 'release candidate')) {
                $blocks[$i]['content']['items'][$n]['answer'] = $words['why_ready'];

                return [$blocks, $sections, ['is it ready']];
            }
        }
    }

    return [$blocks, $sections, []];
});
$page('docs/updating', static function (array $blocks, array $sections) use ($words, $swap): array {
    $said = $swap($blocks, '<p>On a server that can\'t reach GitHub, upload the release ZIP on the same screen.</p>', $words['updating_zip']) ? ['a ZIP checked first'] : [];

    return [$blocks, $sections, $said];
});
$page('get', static function (array $blocks, array $sections) use ($words, $swap): array {
    $said = $swap($blocks, $words['get_line'][0], $words['get_line'][1]) ? ['the version line'] : [];

    return [$blocks, $sections, $said];
});
$page('boxlet-vs-wordpress', static function (array $blocks, array $sections) use ($words): array {
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'cards' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['heading'] === 'Cookies' && $item['body'] !== $words['wp_cookies']) {
                $blocks[$i]['content']['items'][$n]['body'] = $words['wp_cookies'];

                return [$blocks, $sections, ['the Cookies card']];
            }
        }
    }

    return [$blocks, $sections, []];
});

// ---- the changelog's v0.2.0, first ---------------------------------------------------------------------
$page('changelog', static function (array $blocks, array $sections) use ($words, $has, $insert): array {
    if ($has($blocks, $words['changelog'][1]['heading'])) {
        return [$blocks, $sections, []];
    }
    [$blocks, $sections] = $insert($blocks, $sections, 0, [['style' => [], 'blocks' => [$words['changelog']]]]);

    return [$blocks, $sections, ['v0.2.0 added']];
});

$finish();
