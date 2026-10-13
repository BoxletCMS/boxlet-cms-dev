<?php

/*
 * The words of content-1.php (PLAN.md D-246): what each page gains, the three new pages, their
 * pictures' alt text and where the footer lists them. Uses content-1.php's helpers. Every fact is
 * the product's as of v0.1.0-rc.9, checked against its code.
 */

/** The new pictures, each with its alt text. */
$newPictures = [
    '40-embed-before-play.png' => 'A YouTube video on a Boxlet page before it is pressed: a grey frame with a play button, nothing loaded from YouTube yet.',
    '41-form-when-sent.png' => 'A form\'s settings for when it is sent: button text, thank-you message, email me each message, and a reply to the visitor.',
    '42-admin-croatian.png' => 'The Boxlet admin in Croatian: the Overview with pages, pictures, visitors, messages, languages and design.',
];

/** path => [where: 'before-last' or 'end', the sections it gains] */
$additions = [
    '' => ['before-last', [
        $one($text('Runs where your sites already live', $p(
            'Boxlet CMS is a self-hosted PHP CMS for ordinary shared hosting: PHP 8.1 or newer, MySQL, MariaDB or SQLite, and Apache or nginx. You don\'t need SSH, Composer, Node or a build step.',
            'Upload one ZIP over FTP or your host\'s file manager and open <code>install.php</code>. The installer checks the server before it changes anything, sets up the database and writes its own settings. On cPanel the files go straight into <code>public_html</code>. <a href="site:docs/installing">How to install</a>',
        ))),
        $one($questions('Questions people ask first', [
            ['Is Boxlet CMS free?', 'Yes. It\'s open source under the MIT licence. There\'s no paid edition and no account to sign up for.'],
            ['Do I need SSH or Composer?', 'No. Everything Boxlet runs on is in the ZIP, its libraries included. FTP and a browser are enough.'],
            ['Will it run on my hosting?', 'If the host has PHP 8.1 and MySQL, MariaDB or SQLite, almost certainly. The installer checks first and names anything that\'s missing.'],
            ['How is it different from WordPress?', 'WordPress can be made into anything, with plugins. Boxlet does what a small site needs, built in: design, pages, pictures, languages, forms, statistics and SEO. <a href="site:boxlet-vs-wordpress">Boxlet vs WordPress</a>'],
            ['Does my site need a cookie banner?', 'Boxlet sets no cookies for visitors, so usually not. <a href="site:website-without-cookie-banner">A website without a cookie banner</a>'],
            ['Can I move an existing site to Boxlet?', 'There\'s no importer: pages are written in Boxlet\'s editor. The old site\'s addresses can be sent to the new pages with redirects, each with a count of how often it\'s used.'],
        ])),
    ]],
    'features/languages' => ['before-last', [
        $one($text('Translate a page in one press', $p(
            'In the page editor, the language menu lists every language the site has. <strong>Translate</strong> makes a draft copy of the page in that language, with its pictures and layout, linked to the original. Replace the words, then publish it when it\'s ready.',
            'Until then the page isn\'t shown in that language at all. Its address there says “not found”, and the language switcher leads to that language\'s home page instead of a half-translated one.',
        ))),
        $one($text('When the original changes', $p('A block whose words changed in the original since it was translated is marked in the editor, with what the original says now beside it. <strong>Mark as up to date</strong> clears it once you\'ve followed. The pages list counts the blocks that are behind.'))),
        $one($text('What follows by itself', $ul(
            'Menus are per language, so each can have its own items and labels.',
            'Every published version names the others with <code>hreflang</code>, the main language\'s as <code>x-default</code>.',
            'A picture with no alt text in a language uses the main language\'s.',
            'The main language has no prefix and is chosen when you install. The others get theirs: <code>/de/</code>, <code>/hr/</code>.',
        ))),
        $one($shot('42-admin-croatian.png', 'The admin speaks your language', $p('The admin uses the site\'s main language where Boxlet has it: Croatian and English so far, with more to come. Dates and numbers are written its way too.'), 'image-right')),
    ]],
    'features/forms' => ['before-last', [
        $one($text('Making a form', $p(
            'In <strong>Forms</strong>, a new form starts with a name, an email and a message field. Add short text, email, phone, long text, a list of choices or a tick box, put them in order and mark which are required. Then put it on any page with the Form block, beside its words or under them.',
            'A form belongs to one language, so a site in two languages has one for each.',
        ))),
        $one($shot('41-form-when-sent.png', 'When a message is sent', $p('Write your own thank-you message and button text, or keep the usual ones in the form\'s language. Each message can be emailed to you, and the visitor can get an automatic reply that you write.'), 'image-right')),
        $one($text('Every message is kept', $p('Messages are kept in the admin with the page they were sent from, whether or not email works. New ones are marked, and the Overview shows how many are unread. Reply by email from the message, or download them all as a spreadsheet (CSV). The sender\'s IP address is never stored: the spam limit uses a salted hash of it.'))),
    ]],
    'features/the-admin' => ['before-last', [
        $one($shot('42-admin-croatian.png', 'In the site\'s language', $p('The admin speaks the language you chose for the site when you installed it, where Boxlet has it: Croatian and English so far. Dates and numbers are written its way too.'), 'image-left')),
        $one($text('Safe to hand over', $ul(
            'Its own design, light or dark. It never takes on the site\'s colours, so a bad colour can\'t lock you out of the screen that fixes it.',
            'After five wrong passwords in a quarter of an hour, logging in is paused. A browser that has logged in here before isn\'t stopped by someone else\'s guesses.',
            'A login ends after four hours unused, and after a week at most.',
            'Every change is in the activity log: what changed, and when.',
            'Backups and updates have their own screens, and a backup is made before every update.',
        ))),
        $one($text('One administrator', $p('There are no user accounts and no roles to set up. If a client edits their own site, they\'re the administrator. Visitors never log in.'))),
    ]],
    'features/pictures' => ['before-last', [
        $one($text('Files to download', $p('PDFs, Word and Excel documents and ZIP files go in the same library as pictures. The Downloads block lists them with their type and size, and counts every download.'))),
        $one($text('Open larger', $p('A Picture or Gallery block can open its pictures larger on a press, over the page, with arrows through a gallery. The small script that does it loads only on a page that uses it.'))),
    ]],
    'docs' => ['end', [
        $one($text('What you need', $ul(
            'A host with {{snippet:requirements}}.',
            'An FTP client, or your host\'s file manager.',
            'A browser. Nothing else: no SSH, no Composer, no build step.',
        ) . $p('The same guides are in the README inside the ZIP.'))),
    ]],
    'docs/backups' => ['end', [
        $one($text('Made in steps', $p('A backup is made a little at a time, so it finishes on a slow shared host too, without running into the server\'s time limit. It carries on by itself while the page is open; if you leave, <strong>Continue</strong> picks it up.'))),
        $one($text('Before an update or a restore', $p('One is made on its own before every update and every restore, and marked that way in the list, so either can be undone.'))),
        $one($questions('Questions', [
            ['Can I restore a backup onto another server?', 'Yes. Install Boxlet there, put the downloaded ZIP in <code>storage/backups</code> and restore it from the list. If the backup\'s login differs from yours, you log in with the backup\'s email and password, and the restore carries on.'],
            ['How much room do backups take?', 'The list shows each backup\'s size and all of them together. Delete the ones you don\'t need, and keep a downloaded copy somewhere else.'],
        ])),
    ]],
    'docs/locked-out' => ['end', [
        $one($text('Too many wrong passwords?', $p('After five wrong passwords in a quarter of an hour, logging in is paused for a while. A browser that has logged in here before isn\'t stopped by someone else\'s guesses, so nobody can keep you out just by knowing your address.'))),
        $one($text('Recovery codes', $p('When you switch on two-step login you get ten recovery codes. Each works once, in place of the code from your phone. Keep them somewhere other than the phone.'))),
    ]],
    'get' => ['before-last', [
        $one($text('What\'s in the ZIP', $p(
            'Everything the site runs on, its libraries included, so there\'s nothing to install with Composer. A README with installing, updating and backups.',
            'The demo\'s pictures aren\'t in it: the installer fetches them only if you ask for the demo.',
        ))),
        $one($text('Updates', $p('Once it\'s installed, Boxlet updates itself from the admin. It finds the new release on GitHub, checks it against the published checksum, makes a backup first, and can roll back. <a href="site:docs/updating">Updating</a> · <a href="site:changelog">What\'s new</a>'))),
    ]],
];

/** The three new pages. */
$newPages = [
    [
        'path' => 'boxlet-vs-wordpress',
        'title' => 'Boxlet vs WordPress',
        'seo_title' => 'Boxlet CMS vs WordPress for a small website',
        'description' => 'When a small site is better on Boxlet CMS and when on WordPress: plugins, cookies, speed, design, languages and updates, compared fairly.',
        'sections' => [
            $one($hero('Boxlet or WordPress?', 'For a site of a few pages, a fair comparison, including what Boxlet doesn\'t do.')),
            $one($text('', $p(
                'WordPress runs a large part of the web and can be made into almost anything. That\'s its strength, and for a small site it\'s also the work: a theme, plugins for SEO, forms, images and security, updates for each of them, and a cookie banner when one of them sets cookies.',
                'Boxlet starts from the other end. It does the handful of things a small site needs, built in, and leaves the rest out.',
            ))),
            $one($cards('Side by side', [
                ['Plugins', 'WordPress: one for each need, from many authors. Boxlet: none, and none needed. Design, forms, pictures, languages, statistics, redirects and SEO are built in.'],
                ['Cookies', 'WordPress: depends on the theme and plugins, and often means a banner. Boxlet: no cookies for visitors, so usually no banner.'],
                ['Speed', 'WordPress: depends on the theme and plugins. Boxlet: next to no JavaScript and a page cache; its demo scores 100 on PageSpeed on a computer and 99 on a phone.'],
                ['Design', 'WordPress: a theme, then a page builder for more. Boxlet: 21 design sets, then colours and typefaces of your own, and text that\'s hard to read is refused.'],
                ['Languages', 'WordPress: a translation plugin. Boxlet: built in, with an address and menus for each language and hreflang written for you.'],
                ['Updates', 'WordPress: the core, the theme and each plugin, on their own schedules. Boxlet: one update from the admin, with a backup first and a way back.'],
            ])),
            $one($shot('23-redirects.png', 'Your WordPress rankings come with you', $p(
                'Moving doesn\'t mean starting again in search. Boxlet has redirects built in: in <strong>Redirects</strong>, add each old WordPress address and the page it lives at now. Visitors and search engines are sent on with a permanent 301, which tells Google the page has moved, so its place in the results moves to the new address.',
                'Addresses such as <code>/2019/05/about-us/</code> or <code>/?p=12</code> work as typed; capital letters, a last slash and the order of a query don\'t matter. Each rule counts how often it\'s used, so you can see old links still arriving. For a site of a dozen pages, that\'s about ten minutes.',
            ), 'image-right')),
            $one($text('When WordPress is the better choice', $ul(
                'You need a shop, a membership area, or a blog with comments.',
                'Several people log in, with different roles.',
                'The site depends on a particular plugin or theme.',
                'You want the largest choice of hosts and freelancers who already know the system.',
            ))),
            $one($text('When Boxlet is the better choice', $ul(
                'A site of a few pages for a business, a practice, a venue or a person.',
                'You build such sites for others and want each to look designed without writing CSS.',
                'You\'d rather not have a cookie banner, or plugins to keep up to date.',
                'Ordinary shared hosting, with no SSH.',
            ))),
            $one($questions('Questions', [
                ['Can I move a WordPress site to Boxlet?', 'Yes, without losing its place in search. There\'s no importer: you write the pages in Boxlet\'s editor, which for a small site is an afternoon, then send each old WordPress address to its new page with a permanent redirect.'],
                ['What about security?', 'Boxlet has little to attack: no plugins, one administrator, optional two-step login, uploads checked by their contents and kept outside the web root, and updates from the admin with a backup first.'],
                ['Does Boxlet cost anything?', 'No. Like WordPress, it\'s free and open source; Boxlet is MIT licensed.'],
            ])),
            $one($cta('See for yourself', 'The demo is a real Boxlet site, an arts centre in an old printing works.', 'Open the demo', DEMO)),
        ],
    ],
    [
        'path' => 'website-without-cookie-banner',
        'title' => 'A website without a cookie banner',
        'seo_title' => 'A website without a cookie banner · Boxlet CMS',
        'description' => 'When a website needs no cookie banner, what usually makes one necessary, and how Boxlet CMS keeps a small site free of cookies and trackers.',
        'sections' => [
            $one($hero('A website without a cookie banner', 'Most small sites need one only because of something they load. Leave that out, and the banner can go too.')),
            $one($text('When a banner is needed', $p(
                'Under the EU\'s ePrivacy rules and the GDPR, a site has to ask before it stores anything in a visitor\'s browser that isn\'t strictly needed for what the visitor asked for, and before it tracks them. A site that does neither has nothing to ask about.',
                'This page explains how Boxlet works. It isn\'t legal advice: whether your site needs consent depends on everything on it.',
            ))),
            $one($text('What usually brings the banner', $ul(
                'An analytics script, such as Google Analytics.',
                'A YouTube video or a Google Map embedded the usual way, which loads the provider\'s scripts and cookies before anyone presses play.',
                'Fonts loaded from another company\'s servers.',
                'Social media buttons and tracking pixels.',
                'Plugins that add any of these without saying so.',
            ))),
            $one($shot('05-cookie-scan.png', 'No cookies, checked', $p('Boxlet sets no cookies for visitors: not on pages, not on the 404, not when someone sends a form. We scanned the demo for cookies before consent and found none, in any category.'), 'image-right')),
            $one($shot('08-settings-statistics.png', 'Statistics without a tracking script', $p('Visits are counted by the site itself, on your own server. A visitor is recognised for one day only, by an anonymous key that changes every day; the IP address makes the key and is never stored. Nothing is added to the page.'), 'image-left', ['label' => 'How it counts', 'url' => 'site:features/privacy'])),
            $one($shot('40-embed-before-play.png', 'Videos and maps on a press', $p('A YouTube or Vimeo video, and a map, show a placeholder or a cover picture until the visitor presses it. Only then is the provider asked for anything. YouTube then comes from youtube-nocookie.com, and a map can be OpenStreetMap.'), 'image-right')),
            $one($text('Fonts and everything else', $p('All 27 typefaces are served from your own server. Pictures, scripts and stylesheets come from the site itself: nothing is fetched from another company while a page loads.'))),
            $one($text('For your privacy policy', $p('Settings writes a paragraph about the statistics for your privacy policy, from your own settings, in English or Croatian. A starting point, not legal advice.'))),
            $one($cta('Try it on your next small site', 'Ordinary shared hosting is enough: PHP 8.1 and MySQL or SQLite.', 'Download Boxlet', 'site:get')),
        ],
    ],
    [
        'path' => 'changelog',
        'title' => 'Changelog',
        'seo_title' => 'Boxlet CMS changelog: what changed in each version',
        'description' => 'What each release of Boxlet CMS added and fixed, newest first, from the release candidates for v0.1 on.',
        'sections' => [
            $one($hero('Changelog', 'What each version added and fixed, newest first.')),
            $one($text('v0.1.0-rc.9 · 13 October 2026', $ul(
                'The admin speaks the site\'s main language: Croatian and English so far. Dates and numbers follow it.',
                'Visitors\' words in Portuguese; Slovenian is no longer shipped.',
                'Link previews: Open Graph and Twitter tags on every page, a sharing picture per page, and the site\'s name for search engines.',
                'The maintenance page shows your own message, in the site\'s language.',
                'A word too long for a heading breaks instead of running off a phone screen.',
                'Fixed: the Pages and Media lists on a phone.',
            ))),
            $one($text('v0.1.0-rc.8 · 12 October 2026', $ul(
                'Open larger: Picture and Gallery blocks can show a picture over the page, with arrows through a gallery.',
                'The country and city databases for statistics download with their progress shown in Settings.',
            ))),
            $one($text('v0.1.0-rc.7 · 12 October 2026', $ul(
                'From a security review: a page with a form is never kept in the page cache, so its timing check holds.',
                'Someone guessing passwords can no longer keep the administrator out.',
                'A two-step code works once, and a login ends after four hours unused, a week at most.',
            ))),
            $one($text('v0.1.0-rc.6 · 12 October 2026', $ul(
                'A form beside its words keeps them close.',
                '“Made with Boxlet CMS” leads to boxletcms.org.',
            ))),
            $one($text('Earlier', $p('The previews and the first release candidates, with their notes, are on <a href="' . RELEASES . '">GitHub</a>.'))),
        ],
    ],
];

/** [menu, label, path]: the new pages in the footer, at the end of each list. */
$menuItems = [
    ['Product', 'Boxlet vs WordPress', 'boxlet-vs-wordpress'],
    ['Product', 'No cookie banner', 'website-without-cookie-banner'],
    ['Help', 'Changelog', 'changelog'],
];
