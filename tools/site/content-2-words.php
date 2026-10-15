<?php

/*
 * The words of content-2.php (PLAN.md D-274): AI translation, copying into a language, forms that
 * keep messages only when told to, v0.1.0. Every fact is the product's as of v0.1.0, checked
 * against its code; the demo's numbers are the owner's (2026-10-15: 14 pages into Croatian with
 * Claude Opus 5.5, its menu, pictures and forms included, about 30 cents).
 *
 * No `{{` anywhere: a snippet tag in these words would be read as one on the site.
 */

$p = static fn (string ...$paragraphs): string => implode('', array_map(static fn (string $s): string => '<p>' . $s . '</p>', $paragraphs));
$ul = static fn (string ...$items): string => '<ul>' . implode('', array_map(static fn (string $s): string => '<li>' . $s . '</li>', $items)) . '</ul>';

$words = [
    // The home page's Languages card, one of six: heading, words.
    'home_card' => [
        'Languages, translated by AI',
        $p('Each language gets its own addresses. Claude can translate a whole site into a new one, menus and forms included.'),
    ],

    // A section of its own on the home page, after the admin's.
    'home_section' => ['image_text', [
        'heading' => 'Translate a whole site with AI',
        'body' => $p(
            'Tick the pages, choose a language, tick <strong>and translate with AI</strong>. Claude writes each page into its draft in that language: the words, the title and address, what search engines read, the pictures\' descriptions, the forms and the language\'s own menu. You read it, then publish.',
            'The demo\'s 14 pages went into Croatian this way with Claude Opus 5.5, menu, pictures and forms included, for about 30 cents.',
        ),
        'image' => 'pic:ai-translated.png',
        'image_fit' => 'contain',
        'link' => ['label' => 'AI translation', 'url' => 'site:features/languages'],
    ], 'image-right', []],

    // A question on the home page, after the one about WordPress.
    'home_question' => [
        'Can AI translate my site?',
        '<p>Yes, with your own Anthropic API key: one page or the whole site, into drafts you read before they go live. Translating the demo\'s 14 pages into Croatian, with its menu, picture descriptions and forms, cost about 30 cents. <a href="site:features/languages">How it works</a></p>',
    ],

    // Features: the Multilingual card's words.
    'features_card' => $p('Adding a language takes one screen, and AI can translate the whole site into it.'),

    // Languages: after "Translate a page in one press".
    'languages' => [
        ['blocks' => [['image_text', [
            'heading' => 'Or let AI translate it',
            'body' => $p(
                'With an Anthropic API key in <strong>Settings → AI translation</strong>, the pages list can copy pages into a language <strong>and translate them with AI</strong>, one page or many at once. Claude translates each page into its draft, part by part. You can leave the screen meanwhile: the rest waits until you come back. Nothing is published until you have read it.',
                'It translates everything the page brings with it: its title, its address and what search engines read, every block\'s words, the pictures\' descriptions, its forms, and the language\'s menus and header and footer words.',
            ),
            'image' => 'pic:ai-translated.png',
            'image_fit' => 'contain',
            'link' => null,
        ], 'image-left', []]]],
        ['blocks' => [['stats', [
            'heading' => 'The demo, into Croatian',
            'items' => [
                ['value' => '14', 'label' => 'pages, with the menu, picture descriptions and forms'],
                ['value' => 'Opus 5.5', 'label' => 'Claude\'s model it was translated with'],
                ['value' => '30 ¢', 'label' => 'about what it cost, all of it'],
            ],
        ], 'three', []]]],
        ['blocks' => [['text', [
            'heading' => 'What stays as it is',
            'body' => $ul(
                'Links to your own pages lead to the right language\'s pages, and menus keep their order and levels. Boxlet does that, not the model.',
                'Markup, snippet tags, addresses and the names you list as never to translate.',
                'Words you wrote yourself: a menu label, a form field or a picture\'s description you have written in that language is never replaced.',
                'An answer that fails a check keeps the original words, and the screen says which.',
            ),
        ], '', []]]],
        ['blocks' => [['text', [
            'heading' => 'Your key, your bill',
            'body' => $p('Boxlet sends nothing until you save your own key, and Anthropic bills you for what you use. The models on offer are Anthropic\'s own list, so a new one appears without an update. Opus was the most careful in our tests; Sonnet costs less.'),
        ], '', []]]],
    ],

    // Languages: "What follows by itself", whole.
    'follows' => $ul(
        'A page copied into another language takes its place in that language\'s menus, and its forms come with it as that language\'s. Tick several pages in the list to copy them at once.',
        'Each language\'s menus can still have items and labels of their own.',
        'Every published version names the others with hreflang, the main language\'s as x-default.',
        'A picture with no alt text in a language uses the main language\'s, until AI translation writes one.',
        'The main language has no prefix and is chosen when you install. The others get theirs: /de/, /hr/.',
    ),

    // Forms: the gallery's heading and first caption.
    'forms_gallery' => [
        'Emailed to you, kept only if you want',
        'Each message reaches you by email, with an optional reply to the sender. Keeping it in the admin too is your choice.',
    ],

    // Forms: what was "Every message is kept".
    'forms_kept' => [
        'Kept, or only emailed',
        $p(
            'A form keeps nothing a visitor writes until you turn on <strong>Keep messages here</strong>: each message is only emailed to you, and nothing of it is stored on your server. If email can\'t be sent, the visitor is asked to try again rather than thanked for a message that went nowhere.',
            'Turn it on and messages are kept in the admin as well, with the page they were sent from, whether or not email works. New ones are marked, and the Overview shows how many are unread. Reply by email from the message, or download them all as a spreadsheet (CSV).',
            'Either way, the sender\'s IP address is never stored: the spam limit uses a salted hash of it.',
        ),
    ],

    // Forms: "Making a form", whole.
    'forms_making' => $p(
        'In <strong>Forms</strong>, a new form starts with a name, an email and a message field. Add short text, email, phone, long text, a list of choices or a tick box, put them in order and mark which are required. Then put it on any page with the Form block, beside its words or under them.',
        'A form belongs to one language, so a site in two languages has one for each. A page copied into another language brings its form with it, as that language\'s.',
    ),

    // The changelog's newest entry.
    'changelog' => ['text', [
        'heading' => 'v0.1.0 · 15 October 2026',
        'body' => $ul(
            '<strong>The first release.</strong> From here on, updates keep your site as it is.',
            'Translate with AI: Claude, with your own Anthropic key. One page or many at once, into drafts: titles, addresses, what search engines read, every block, the pictures\' descriptions, forms, menus and header and footer words. The models on offer are Anthropic\'s own list.',
            'Copy pages into another language from the pages list, several at once. Their places in that language\'s menus and their forms come with them, and Navigation can match a language\'s menus with the main language\'s.',
            'Publish or unpublish several pages at once.',
            'Forms keep messages only when you turn it on. By default each message is only emailed to you.',
            'The statistics chart shows a day\'s figures under the pointer.',
            'Fixed: long titles on a phone in the Pages and Media lists.',
        ),
    ], '', []],

    // Download: what the version line says the version is.
    'get' => 'the first release',
];
