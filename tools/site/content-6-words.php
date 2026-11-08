<?php

/*
 * The words content-6.php puts on boxletcms.org for v0.2 (PLAN.md D-300). Every claim checked in
 * the code it describes: McpTools (the tools a key is offered), McpKeys and the AI access panel
 * (the three levels, the Claude Code line), McpTranslate (translation through the site's own),
 * ScriptCatalogue and Consent (what is recognised, what waits for a yes, the cookie-free ones
 * that do not), the layout's five scripts, the forms' webhook (lang/en/forms.php), Mail's Brevo
 * and Mailgun, media-upload.js, the Statistics screen's settings tab, the v0.2.0 release notes.
 * No `{{` anywhere except the Download page's own snippet tag, left as it stands.
 */

$p = static fn (string ...$paragraphs): string => implode('', array_map(static fn (string $s): string => '<p>' . $s . '</p>', $paragraphs));
$ul = static fn (string ...$items): string => '<ul>' . implode('', array_map(static fn (string $s): string => '<li><p>' . $s . '</p></li>', $items)) . '</ul>';
$shot = static fn (string $file, string $h2, string $body, string $layout, ?array $link = null): array => ['image_text', ['heading' => $h2, 'body' => $body, 'image' => 'pic:' . $file, 'image_fit' => 'contain', 'link' => $link], $layout, []];
$text = static fn (string $h2, string $body): array => ['text', ['heading' => $h2, 'body' => $body], '', []];
$one = static fn (array $block, array $style = []): array => ['style' => $style, 'blocks' => [$block]];

$aiIntro = 'Connect Claude Code or another AI app, and it works on your site as you would: pages as drafts, pictures, design sets. You read them and publish.';

return [
    // ---- the new page, features/ai-apps -----------------------------------------------------
    'ai_page' => [
        'title' => 'AI apps',
        'description' => $aiIntro,
        'sections' => [
            $one(['hero', ['heading' => 'Work with your AI assistant', 'subheading' => $aiIntro, 'cta' => null], '', ['height' => 'auto']]),
            $one($shot('ai-access.png', 'One key, one line', $p(
                'In <strong>Settings → AI → AI access</strong>, make a key for each app and choose what it may do. For Claude Code the line that connects it is shown with the key: paste it into a terminal. Other apps ask for the address and the key.',
                'The address is your own site\'s, through the Model Context Protocol (MCP). There\'s nothing to install on the server.',
            ), 'image-right')),
            $one($text('What it can do', $ul(
                'Read your pages, pictures, the blocks there are and your design.',
                'Write new pages and changes to pages as drafts, with the same blocks you use.',
                'Add pictures from an address on the web, with their descriptions.',
                'Make design sets for you to try in Appearance. One it keeps is marked <strong>AI</strong> under Your designs.',
                'Translate pages through Boxlet\'s own AI translation, with your key, into drafts in that language.',
            ))),
            $one($text('Three kinds of key', $ul(
                '<strong>Read only:</strong> it can look, and change nothing.',
                '<strong>Read, and write drafts, pictures and design sets:</strong> everything waits for you to publish it or try it.',
                '<strong>And publish pages:</strong> only a key like this may put a page live without you.',
            ))),
            $one($text('Nothing behind your back', $p(
                'An AI app can\'t delete anything. Every change it makes is in the activity log, and you can revoke a key at any time: from then on it opens nothing.',
            ))),
            $one($text('Ask in your own words', $p(
                'Tell it what you want, the way you\'d tell a person: <em>“Make a site for a small bakery: a home page, the menu, about us and contact, in a warm design.”</em> Or later: <em>“When the Croatian pages are done, translate them into English.”</em> It finds the blocks and the design settings itself, and leaves the drafts for you.',
            ))),
            $one(['cta', ['heading' => 'Try it on your next small site', 'body' => 'Ordinary shared hosting is enough: PHP 8.1 and MySQL or SQLite.', 'action' => ['label' => 'Download Boxlet', 'url' => 'site:get']], '', []]),
        ],
    ],
    'admin_next' => ['heading' => 'Next: Work with your AI assistant', 'body' => 'Claude Code or another AI app, writing drafts you publish.', 'action' => ['label' => 'AI apps', 'url' => 'site:features/ai-apps']],
    'features_card' => ['image' => 'pic:ai-access.png', 'heading' => 'Work with your AI assistant', 'body' => $p('Claude Code and other AI apps write drafts, pictures and design sets. You publish.'), 'link' => ['label' => 'AI apps', 'url' => 'site:features/ai-apps']],

    // ---- home --------------------------------------------------------------------------------
    'home_section' => $shot('ai-access.png', 'Work with your AI assistant', $p(
        'Connect Claude Code or another AI app with a key from the admin, and ask in your own words: a new page, a design set, the whole site in another language. It writes drafts, adds pictures and makes design sets for you to try.',
        'Nothing goes live until you publish it, nothing is deleted, and every change is in the activity log.',
    ), 'image-right', ['label' => 'AI apps', 'url' => 'site:features/ai-apps']),
    'home_question' => ['Can an AI app work on my site?', $p('Yes. Make a key in the admin, and Claude Code or another app that speaks MCP reads your site and writes drafts, pictures and design sets. You choose whether a key may also publish. <a href="site:features/ai-apps">AI apps</a>')],
    'home_cookie_answer' => $p('Boxlet sets no cookies for visitors, so usually not. If you add a script that does, such as Google Analytics, Boxlet asks visitors itself, in a box drawn from your site\'s design. <a href="site:website-without-cookie-banner">A website without a cookie banner</a>'),

    // ---- privacy -------------------------------------------------------------------------------
    'privacy_sections' => [
        $one($shot('scripts-cookies.png', 'Your own scripts, asked about properly', $p(
            'Want Google Analytics, a Meta pixel or a chat window anyway? Paste its code into <strong>Scripts &amp; cookies</strong>. Boxlet recognises Google Analytics, Tag Manager, the Meta pixel, Hotjar, Microsoft Clarity and Matomo, fills in the cookies they set, and keeps them from running until a visitor agrees.',
            'Plausible, Fathom, Umami and Simple Analytics set no cookies, so they run without asking.',
        ), 'image-left')),
        $one($shot('consent-choose.png', 'A box drawn from your design', $p(
            'The box that asks takes your site\'s typefaces, colours and corners. <strong>Reject all</strong> is as easy as Accept all, a visitor can choose statistics and marketing apart, and <strong>Cookie settings</strong> at the bottom of every page changes the answer. It appears only while a script is waiting for one.',
        ), 'image-right')),
    ],
    'privacy_other_tool' => $p('Add its code in Scripts &amp; cookies; if it sets cookies, visitors are asked first. Boxlet\'s own statistics can be switched off on the Statistics screen.'),

    // ---- a website without a cookie banner ------------------------------------------------------
    'banner_section' => $shot('consent-box.png', 'If you do add a script that sets cookies', $p(
        'Then the site needs to ask, and Boxlet does it for you. Paste the script into Scripts &amp; cookies and it waits until a visitor agrees, in a box drawn from your site\'s design, with Reject all as easy as Accept all. The code is in the page only as a template that never runs before then.',
        'Without such a script there\'s no box, and nothing to ask.',
    ), 'image-left', ['label' => 'Your own scripts', 'url' => 'site:features/privacy']),
    'banner_policy' => $p('The Statistics screen writes a paragraph about the statistics for your privacy policy, from your own settings, in English or Croatian. A starting point, not legal advice.'),

    // ---- pictures ------------------------------------------------------------------------------
    'pictures_sections' => [
        $one($shot('bulk-upload.png', 'Drop as many as you like', $p(
            'Drop dozens of pictures on the library at once. Each one goes up on its own, with its own progress bar, and every size is made before it says Done, even on a host with a short time limit.',
            'A picture an earlier upload left unfinished finishes by itself the next time the library is opened.',
        ), 'image-right')),
        $one($shot('media-grid.png', 'A list or a grid', $p(
            'See the library as a list with sizes and where each picture is used, or as a grid of pictures. Both have the same search and filters, a tick on each to delete several at once, and a ⋯ menu.',
        ), 'image-left')),
    ],

    // ---- forms --------------------------------------------------------------------------------
    'forms_mail' => ['SMTP, Resend, Brevo or Mailgun', $p('Resend, Brevo and Mailgun send through their web APIs, which work on hosts that block SMTP. A test button tells you it works before a client finds out it doesn\'t.')],
    'forms_section' => $shot('form-webhook.png', 'Sent on to your other tools', $p(
        'Give a form a web address and each message is sent there as JSON as it arrives: to Zapier, Make, n8n or your own code. It\'s signed, so the receiver can tell it came from your site.',
        'A message the address doesn\'t take is kept, for up to 30 days, with <strong>Send again</strong> beside it.',
    ), 'image-left'),

    // ---- languages, the admin, why, updating, Download, Boxlet vs WordPress --------------------
    'languages_ai' => [
        'Settings → AI translation' => 'Settings → AI → Translation',
        'Nothing is published until you have read it.</p>' => 'Nothing is published until you have read it.</p><p>An AI app connected to the site translates the same way: asked to, it uses this translation and your key, into drafts. <a href="site:features/ai-apps">AI apps</a></p>',
    ],
    'admin_search' => $p('Search pages, pictures and settings from anywhere. Settings themselves are in tabs: General, Maintenance, Languages, Email, AI and Your login.'),
    'why_ready' => $p('Yes. v0.1.0 came out on 15 October 2026 and v0.2.0 on 1 November. Updates keep your site as it is: no fresh install between versions.'),
    'updating_zip' => $p('On a server that can\'t reach GitHub, upload the release ZIP on the same screen. Boxlet checks it against the official releases and says which version it is before anything is replaced.'),
    'get_line' => ['Version {{snippet:version}}, the first release. MIT licensed.', 'Version {{snippet:version}}. MIT licensed.'],
    'wp_cookies' => $p('WordPress: depends on the theme and plugins, and often means a banner. Boxlet: no cookies for visitors, so usually no banner; add a script that sets them, and Boxlet asks visitors itself.'),

    // ---- the changelog ----------------------------------------------------------------------------
    'changelog' => $text('v0.2.0 · 1 November 2026', $ul(
        '<strong>AI apps can work on your site.</strong> Claude Code and other apps connect through MCP with a key from Settings → AI → AI access: they read the site and write pages as drafts, pictures and design sets. A key reads only, writes, or also publishes; nothing is deleted, and every change is in the activity log. <a href="site:features/ai-apps">AI apps</a>',
        'Scripts &amp; cookies: add Google Analytics, Tag Manager, a Meta pixel, Hotjar, Clarity, Matomo or code of your own. The common ones are recognised with their cookies, and a statistics or marketing script runs only after a visitor agrees, in a box drawn from your design.',
        'Forms send each message on to a web address, signed, for Zapier, Make, n8n or your own code, with Send again for one that wasn\'t taken. Brevo and Mailgun beside SMTP and Resend.',
        'Drop as many pictures as you like: each goes up with its own progress bar, and an unfinished one finishes by itself. The library as a grid as well as a list.',
        'Settings in tabs, with AI under one; Statistics\' settings on the Statistics screen; Navigation in two tabs.',
        'Updating from a ZIP checks it against the official releases first.',
        'A long word or a heading in a narrow column fits its column.',
    )),
];
