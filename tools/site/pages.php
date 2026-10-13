<?php

/*
 * boxletcms.org's sixteen pages (PLAN.md D-228), in the words of Claude Design's content spec.
 * Each section holds one block unless a page reads as a document (Docs). A block is
 * [type, content, layout or '' to follow the design set, options]; a section is its style
 * and its blocks. Markers: `pic:{file}` a screenshot, `site:{path}` a page of this site.
 */

const DEMO = 'https://demo.boxletcms.org';
const GITHUB = 'https://github.com/BoxletCMS/boxlet-cms-dev';

$p = static fn (string ...$paragraphs): string => implode('', array_map(static fn (string $s): string => '<p>' . $s . '</p>', $paragraphs));
$ul = static fn (string ...$items): string => '<ul>' . implode('', array_map(static fn (string $s): string => '<li><p>' . $s . '</p></li>', $items)) . '</ul>';
$ol = static fn (string ...$items): string => '<ol>' . implode('', array_map(static fn (string $s): string => '<li><p>' . $s . '</p></li>', $items)) . '</ol>';
$one = static fn (array $block, array $style = []): array => ['style' => $style, 'blocks' => [$block]];
$hero = static fn (string $h1, string $sub, ?array $cta = null, string $height = 'auto'): array => ['hero', ['heading' => $h1, 'subheading' => $sub, 'cta' => $cta], '', ['height' => $height]];
$text = static fn (string $h2, string $body): array => ['text', ['heading' => $h2, 'body' => $body], '', []];
$shot = static fn (string $file, string $h2, string $body, string $layout, ?array $link = null): array => ['image_text', ['heading' => $h2, 'body' => $body, 'image' => 'pic:' . $file, 'image_fit' => 'contain', 'link' => $link], $layout, []];
$picture = static fn (string $file, string $caption = ''): array => ['picture', ['image' => 'pic:' . $file, 'caption' => $caption], '', []];
$pair = static fn (string $h2, array $a, array $b): array => ['gallery', ['heading' => $h2, 'items' => [['image' => 'pic:' . $a[0], 'caption' => $a[1]], ['image' => 'pic:' . $b[0], 'caption' => $b[1]]]], 'two', []];
$questions = static fn (string $h2, array $qa): array => ['accordion', ['heading' => $h2, 'items' => array_map(static fn (array $q): array => ['question' => $q[0], 'answer' => '<p>' . $q[1] . '</p>'], $qa)], '', []];
$cta = static fn (string $h, string $body, string $label, string $url, string $layout = ''): array => ['cta', ['heading' => $h, 'body' => $body, 'action' => ['label' => $label, 'url' => $url]], $layout, []];
$download = $one($cta('Try it on your next small site', 'Ordinary shared hosting is enough: PHP 8.1 and MySQL or SQLite.', 'Download Boxlet', 'site:get'));

// Image and text, right, left, right… down a feature page (the spec's rhythm).
$alternate = static function (array ...$shots) use ($shot, $one): array {
    $sections = [];
    foreach ($shots as $i => $s) {
        $sections[] = $one($shot($s[0], $s[1], $s[2], $i % 2 === 0 ? 'image-right' : 'image-left', $s[3] ?? null));
    }

    return $sections;
};

// The feature pages in reading order: each ends with a way on to the next.
$features = [
    'privacy' => ['No cookies, own statistics', 'No cookies. No cookie banner.', 'Boxlet doesn\'t set a single cookie for your visitors. Not on pages, not on the 404, not when someone sends a form.', '02-statistics.png'],
    'design' => ['Design', 'Design, without writing CSS', 'This is the part Boxlet is really about.', '10-appearance-design-sets.png'],
    'writing' => ['Writing pages', 'Write on the page itself', 'Click a heading and type. Select words for bold, a link or a list.', '14-structure-dragging.png'],
    'pictures' => ['Pictures and files', 'Upload once', 'Boxlet makes every size a page needs, in AVIF and WebP when your server can.', '20-media-library.png'],
    'speed-seo' => ['Speed and SEO', 'Fast without trying', 'It gets there by leaving things out, and by keeping what it can.', '04a-pagespeed-desktop.png'],
    'languages' => ['Languages', 'Multilingual from day one', 'Adding a language takes one screen.', '24-settings-languages.png'],
    'forms' => ['Forms and email', 'Forms, without a plugin', 'Text, email, phone, long text, choice and checkbox fields.', '26-form-builder.png'],
    'the-admin' => ['The admin', 'An admin that\'s nice to be in', 'Its own design, light or dark.', '29-command-palette.png'],
];
$keys = array_keys($features);
$feature = static function (string $key, array $body) use ($features, $keys, $hero, $one, $cta, $download): array {
    [$title, $h1, $sub] = $features[$key];
    $at = array_search($key, $keys, true);
    $next = $keys[$at + 1] ?? null;
    $last = $next === null
        ? $download
        : $one($cta('Next: ' . $features[$next][1], $features[$next][2], $features[$next][0], 'site:features/' . $next, 'beside'), ['surface' => 'plain']);

    return [
        'path' => 'features/' . $key,
        'title' => $title,
        'description' => $sub,
        'sections' => array_merge([$one($hero($h1, $sub))], $body, [$last]),
    ];
};

return [
    [
        'path' => '',
        'title' => 'Home',
        'seo_title' => 'Boxlet: a small CMS for small websites',
        'description' => 'Self-hosted, no cookies, multilingual. Upload a ZIP, answer four questions, and you have a site that looks designed.',
        'sections' => [
            $one($hero('A small CMS for people who build lots of small websites.', 'Upload a ZIP, open a page, answer four questions. Fifteen minutes later you have a multilingual site that looks designed. No shell, no Composer, no build step.', ['label' => 'Download Boxlet', 'url' => 'site:get'], 'tall')),
            $one($picture('01-builder-hero-selected.png', 'The page builder, with the demo\'s home page open.')),
            $one(['stats', ['heading' => 'What a visitor\'s browser gets', 'items' => [
                ['value' => '0', 'label' => 'cookies'],
                ['value' => '0 ms', 'label' => 'blocking time'],
                ['value' => '3', 'label' => 'small scripts, at most'],
                ['value' => '100', 'label' => 'on PageSpeed on a computer, 99 on a phone'],
            ]], 'four', []]),
            $one(['cards', ['heading' => 'The handful of things a small site needs', 'items' => [
                ['heading' => 'No cookie banner', 'body' => $p('Boxlet sets no cookies for visitors, so in the usual case there\'s nothing to ask consent for.'), 'link' => ['label' => 'Privacy and statistics', 'url' => 'site:features/privacy']],
                ['heading' => 'Design without CSS', 'body' => $p('21 design sets that change how a page is composed, not just its colours.'), 'link' => ['label' => 'Design', 'url' => 'site:features/design']],
                ['heading' => 'Write on the page', 'body' => $p('Click a heading and type. What you see is what visitors get.'), 'link' => ['label' => 'Writing pages', 'url' => 'site:features/writing']],
                ['heading' => 'Pictures done for you', 'body' => $p('Upload once. AVIF, WebP and every size are made for you.'), 'link' => ['label' => 'Pictures and files', 'url' => 'site:features/pictures']],
                ['heading' => 'Fast without trying', 'body' => $p('Next to no JavaScript and a page cache. 100 on PageSpeed on a computer, 99 on a phone.'), 'link' => ['label' => 'Speed and SEO', 'url' => 'site:features/speed-seo']],
                ['heading' => 'Languages from day one', 'body' => $p('Each language gets its own addresses, and translations know each other.'), 'link' => ['label' => 'Languages', 'url' => 'site:features/languages']],
            ]], 'grid', []]),
            $one($shot('02-statistics.png', 'Statistics on your own server', $p('Visits are counted by the site itself. No script on the page, nothing sent anywhere, and nobody followed from one day to the next.'), 'image-right', ['label' => 'How it counts', 'url' => 'site:features/privacy'])),
            $one($shot('03-overview.png', 'An admin that\'s nice to be in', $p('Light or dark, ⌘K to find anything, and an overview of what needs your attention. It never takes on the site\'s colours, so a bad colour can\'t lock you out.'), 'image-left', ['label' => 'The admin', 'url' => 'site:features/the-admin'])),
            $one($text('What it won\'t be', $p('Not a blogging platform, not a shop, not a drag-anywhere page builder, and not a plugin marketplace. One administrator, no user accounts. It\'s boring where boring is good, and puts its effort into making sites look right.'))),
            $download,
        ],
    ],
    [
        'path' => 'why',
        'title' => 'Why Boxlet',
        'description' => 'Because a five-page site shouldn\'t take an afternoon of removing things.',
        'sections' => [
            $one($hero('Why another CMS?', 'Because a five-page site shouldn\'t take an afternoon of removing things.')),
            $one($text('', $p(
                'You know how it goes. A client needs a small site. You install a big CMS, then spend the afternoon <em>removing</em> things: comments, widgets, the emoji script. Then you add things back as plugins: one for SEO, one for forms, one for images, one for security, and one for the cookie banner the others made necessary. A year later half of them want a paid upgrade.',
                'Boxlet does the handful of things a small site needs, does them properly, and does them out of the box. That\'s all it tries to do.',
            ))),
            $one($shot('05-cookie-scan.png', 'Checked, not promised', $p('We scanned the demo for cookies before consent: none, in any category. PageSpeed gave it 100 on a computer and 99 on a phone, on 12 October 2026. Accessibility, best practices and SEO were 100 on both.'), 'image-right')),
            $one($pair('', ['04a-pagespeed-desktop.png', 'Computer'], ['04b-pagespeed-mobile.png', 'Phone'])),
            $one($questions('Fair questions', [
                ['Is Boxlet GDPR compliant?', 'Boxlet is built not to collect personal data about visitors. Whether a site complies depends on the whole site, though: if you embed a Google Map or a Vimeo video, that provider\'s rules apply. We\'re a CMS, not your lawyer.'],
                ['Can I add plugins?', 'No. That\'s on purpose. What a small site needs is built in.'],
                ['Can clients log in and edit?', 'There\'s one administrator. If a client edits their own site, they\'re the administrator.'],
                ['Is it ready?', 'This is a release candidate for v0.1. From v0.1 on, updates keep your site as it is: no fresh install between versions.'],
                ['What does it cost?', 'Nothing. It\'s MIT licensed.'],
            ])),
            $one($cta('See for yourself', 'The demo is a real Boxlet site, an arts centre in an old printing works.', 'Open the demo', DEMO)),
        ],
    ],
    [
        'path' => 'features',
        'title' => 'Features',
        'description' => 'Boxlet does a short list of things and tries to do each one properly.',
        'sections' => [
            $one($hero('Everything it does', 'Boxlet does a short list of things and tries to do each one properly.')),
            $one(['cards', ['heading' => '', 'items' => array_map(static fn (string $key): array => [
                'image' => 'pic:' . $features[$key][3],
                'heading' => $features[$key][1],
                'body' => $p($features[$key][2]),
                'link' => ['label' => $features[$key][0], 'url' => 'site:features/' . $key],
            ], $keys)], 'grid', ['per_row' => '2', 'image_shape' => 'wide']]),
            $download,
        ],
    ],
    $feature('privacy', array_merge(
        $alternate(['05-cookie-scan.png', 'Checked in a real browser', $p('We opened every kind of page and sent a form, and the cookie jar stayed empty. Forms keep a salted hash of the sender\'s IP for spam limits, never the address.')]),
        [$one($text('What else stays quiet', $ul(
            'Fonts come from your own server.',
            'Videos and maps load only when pressed. YouTube then comes from youtube-nocookie.com, and a map can be OpenStreetMap.',
            'Three small scripts at most, each only on a page that needs it.',
        )))],
        $alternate(
            ['02-statistics.png', 'Statistics without a tracking script', $p('Visitors and page views by day, compared with the period before. Nothing is added to the page.')],
            ['07-statistics-sources-devices.png', 'Where people came from', $p('The pages people read, where they came from, and their devices, browsers and systems.')],
            ['06-statistics-map.png', 'Where they are', $p('Countries on a map, and if you like regions and cities, from a location database on your own server (DB-IP).')],
            ['08-settings-statistics.png', 'How a visitor is counted', $p('An anonymous key that changes every day, so nobody can be followed from one day to the next. The IP address makes the key and is never stored. You\'re not counted while logged in, and well-known bots aren\'t either. Do Not Track can be honoured.')],
            ['09-privacy-paragraph.png', 'A paragraph for your privacy policy', $p('Written from your own settings, in every language the site speaks. A starting point, not legal advice.')],
        ),
        [$one($questions('', [
            ['What if I already use another analytics tool?', 'Switch Boxlet\'s statistics off in Settings.'],
            ['Can I export the counts?', 'Yes, everything as CSV, and you choose how long counts are kept.'],
        ]))],
    )),
    $feature('design', array_merge(
        $alternate(
            ['10-appearance-design-sets.png', '21 design sets', $p('13 are in Appearance from the start, and 8 more are one click away in the library. Each one changes how a page is composed: reading width, rhythm, section edges, how a hero is arranged.')],
            ['11-appearance-colours.png', 'Then make it yours', $p('A main colour and an optional second one. The whole palette is worked out from them, for a light site or a dark one.')],
            ['11b-appearance-typography.png', '27 typefaces, all on your server', $p('Pair them, set the type scale, spacing, corners and shadows.')],
            ['12-contrast-refused.png', 'Hard-to-read text is refused', $p('If a colour pair would make any text hard to read, Boxlet won\'t publish it and tells you which pair failed. Fix automatically is one press.')],
        ),
        [
            $one($pair('Header and footer', ['13-appearance-header.png', 'Several arrangements for each, a logo for light and dark backgrounds, and a real mobile menu.'], ['13b-appearance-footer.png', ''])),
            $one($text('Designs travel', $p('Export a design as a file and import it on another site. This site\'s own design is one of those files.'))),
        ],
    )),
    $feature('writing', array_merge(
        $alternate(
            ['15-rich-text-selected.png', 'What you see is what visitors get', $p('Pasting from Word leaves no inline styles or leftover classes. What\'s saved is cleaned on the server too.')],
            ['16-section-settings.png', 'Sections and columns', $p('A section holds one to four columns. Each one has a surface, an edge, its spacing, and can be hidden on a phone, a tablet or a computer.')],
            ['14-structure-dragging.png', 'The structure beside the page', $p('Every section and block in a tree. Drag a block into another column or a section up or down. Save a section as a pattern to use again.')],
            ['17-phone-preview.png', 'A phone, a press away', $p('Switch between computer, tablet and phone while you write.')],
            ['18-history-restore.png', 'Drafts that save themselves', $p('Every change is a draft, with undo and redo. Publish puts it on the site. The last five versions are kept, and any can be restored.')],
            ['19-page-tree.png', 'The page tree', $p('Drag a page right to put it under another, left to take it out. Translations follow.')],
        ),
        [$one($text('Fifteen blocks', $p(
            'Hero, text, image and text, picture, gallery, cards, quote, numbers, call to action, questions, logos, downloads, form, video or map, and space.',
            'Snippets let you write opening hours once and use them everywhere, and a year tag keeps a copyright line current. And every page can still be edited in a plain form, without JavaScript.',
        )))],
    )),
    $feature('pictures', array_merge(
        $alternate(
            ['20-media-library.png', 'One library for pictures and files', $p('Search, alt text and captions in each language, and “used on” for every picture. PDFs, documents and ZIPs sit next to them, and the Downloads block counts downloads.')],
            ['21-media-focal-crop.png', 'Focal point and crop', $p('Click the part that must stay in view. A phone photo\'s orientation is fixed for you.')],
        ),
        [$one($text('', $ul(
            'Served straight from disk, without PHP.',
            '<code>&lt;picture&gt;</code> with <code>srcset</code>, width and height always set.',
            'Lazy loading below the fold.',
            'Replace a picture and every page shows the new one.',
            'A picture still in use can\'t be deleted by mistake.',
        )))],
    )),
    $feature('speed-seo', array_merge(
        [
            $one($pair('100 on a computer, 99 on a phone', ['04a-pagespeed-desktop.png', 'PageSpeed Insights, demo.boxletcms.org, 12 October 2026.'], ['04b-pagespeed-mobile.png', ''])),
            $one($text('', $ul(
                'Next to no JavaScript, 0 ms blocking.',
                'One stylesheet for the site, plus the one your design compiles to.',
                'A page cache that empties the moment you change anything.',
                'Versioned addresses, so browsers keep files for a year.',
                'Fonts from your own server.',
            ))),
        ],
        $alternate(
            ['22-seo-panel.png', 'SEO that\'s just there', $p('A title, description and sharing image per page, a canonical link, hreflang and breadcrumbs. sitemap.xml and robots.txt keep themselves up to date.')],
            ['23-redirects.png', 'Addresses that keep working', $p('Rename a page and the old address redirects with a 301. Add your own redirects from an old site, each with a count.')],
        ),
        [$one($text('llms.txt for AI tools', $p('A plain list of your published pages and their descriptions, in every language. One switch turns it off.')))],
    )),
    $feature('languages', array_merge(
        $alternate(
            ['24-settings-languages.png', 'Its own addresses', $p('Each language gets its own addresses: /, /hr/, /de/.')],
            ['25-builder-translation-hr.png', 'Translations know each other', $p('So the language switcher and hreflang come for free.')],
        ),
        [$one($text('', $p('Form labels, buttons and “page not found” are already translated into English, German, Spanish, French, Croatian, Italian and Portuguese.')))],
    )),
    $feature('forms', [
        $one($shot('26-form-builder.png', 'A form builder', $p('Fields in order, required where you say.'), 'image-right')),
        $one($pair('Messages are kept in the admin', ['27a-inbox-list.png', 'And emailed to you as they arrive, with an optional reply to the sender.'], ['27-inbox-message.png', ''])),
        $one($shot('28-settings-email.png', 'SMTP or Resend', $p('Resend\'s web API works on hosts that block SMTP. A test button tells you it works before a client finds out it doesn\'t.'), 'image-left')),
        $one($text('Spam without a CAPTCHA', $p('A hidden field, a timing check and a per-sender limit.'))),
    ]),
    $feature('the-admin', [
        $one($shot('03-overview.png', 'The overview', $p('What changed lately, what needs attention, and which pages people read most.'), 'image-right')),
        $one($shot('29-command-palette.png', '⌘K', $p('Search pages, pictures and settings from anywhere.'), 'image-left')),
        $one($pair('Two-step login, if you want it', ['30-two-step-setup.png', 'Any authenticator app, and ten recovery codes. Never forced.'], ['30b-two-step-recovery-codes.png', ''])),
        $one($shot('31-maintenance-on.png', 'Maintenance mode', $p('Work in peace while visitors see a short message.'), 'image-right')),
    ]),
    ...require __DIR__ . '/docs.php',
];
