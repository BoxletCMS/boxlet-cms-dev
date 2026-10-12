<?php

/*
 * boxletcms.org's Docs (PLAN.md D-228): the README's Installing, Updating, Backups and Locked
 * out?, kept short. Each page reads as one document: its words and pictures in one section,
 * its edge cases in a second. Uses pages.php's helpers.
 */

$doc = static fn (string $slug, string $title, string $sub, array $blocks, ?array $questions = null): array => [
    'path' => 'docs/' . $slug,
    'title' => $title,
    'description' => $sub,
    'sections' => array_values(array_filter([
        $one($hero($title, $sub)),
        ['style' => [], 'blocks' => $blocks],
        $questions === null ? null : $one($questions),
    ])),
];

return [
    [
        'path' => 'docs',
        'title' => 'Docs',
        'description' => 'Short, because there isn\'t much to it.',
        'sections' => [
            $one($hero('Docs', 'Short, because there isn\'t much to it.')),
            $one(['cards', ['heading' => '', 'items' => [
                ['heading' => 'Installing', 'body' => $p('Upload what\'s in the ZIP, open install.php, answer four questions.'), 'link' => ['label' => 'Installing', 'url' => 'site:docs/installing']],
                ['heading' => 'Updating', 'body' => $p('From the admin, with a backup first and a way back.'), 'link' => ['label' => 'Updating', 'url' => 'site:docs/updating']],
                ['heading' => 'Backups', 'body' => $p('The whole site in one ZIP, made and restored from the admin.'), 'link' => ['label' => 'Backups', 'url' => 'site:docs/backups']],
                ['heading' => 'Locked out?', 'body' => $p('Both ways back in need only FTP.'), 'link' => ['label' => 'Locked out?', 'url' => 'site:docs/locked-out']],
            ]], 'grid', ['per_row' => '2']]),
            $one($text('', $p('Something missing? <a href="' . GITHUB . '/issues">Ask on GitHub</a>.'))),
        ],
    ],
    $doc('installing', 'Installing', 'Upload a ZIP, open a page, answer four questions.', [
        $text('Requirements', $p('{{snippet:requirements}}. Imagick for AVIF; without it Boxlet uses GD and serves WebP.', 'That\'s ordinary shared hosting. The installer checks all of it, and names anything that\'s missing in plain words.')),
        $picture('35-installer-server-check.png'),
        $text('On cPanel', $p('Or any host where your domain always serves from <code>public_html</code>.') . $ol(
            'Unpack the ZIP and upload everything inside its <code>boxlet/</code> folder straight into <code>public_html</code>.',
            'There\'s no step two. Boxlet\'s <code>.htaccess</code> serves the site from its <code>public/</code> folder and keeps everything else out of reach. Addresses stay clean: <code>/about</code>, never <code>/public/about</code>.',
        )),
        $text('If you can choose the folder', $p('Upload everything inside <code>boxlet/</code> to a folder of your choice, and point the domain at the <code>public/</code> folder inside it. The rest of Boxlet then sits outside the web entirely.')),
        $text('Then, in both cases', $ol(
            'For MySQL, create an empty database. If cPanel makes it in <code>latin1</code>, that\'s fine: the installer switches an empty database to <code>utf8mb4</code>.',
            'Open <code>https://your-site/install.php</code> and follow the four steps.',
        ) . $p('Before it asks you for anything, the installer checks that nothing beside <code>public/</code> can be opened in a browser, the settings file with your database password included.')),
        $text('Proving you own the server', $p('The installer writes a token to <code>storage/install-token.txt</code> and asks you to paste it in. Open the file over FTP or in your host\'s file manager.')),
        $picture('36-installer-token.png'),
        $text('', $p('<strong>The primary language can\'t be changed later</strong>, so choose it with care.')),
        $text('The demo', $p('If you like, the installer adds a demo site: The Printworks, an arts centre in an old printing works. <a href="' . DEMO . '">It\'s the one at demo.boxletcms.org</a>. Its pictures aren\'t covered by the Boxlet licence; each one\'s source and licence is in <a href="' . GITHUB . '/blob/main/demo_images/CREDITS.md">CREDITS.md</a>.')),
        $text('Clearing the demo', $ol(
            'In <strong>Pages</strong>, tick every page and delete them.',
            'In <strong>Media</strong>, choose <strong>Unused</strong>, tick everything and delete it.',
        ) . $p('The demo\'s forms, footer words and site name are yours to change or remove.')),
    ], $questions('When something is in the way', [
        ['public_html already has a .htaccess file', 'Don\'t overwrite it: cPanel sometimes writes your PHP version into one. Put Boxlet\'s lines below the ones already there. They sit between <code># BEGIN Boxlet</code> and <code># END Boxlet</code>, and an update replaces only those.'],
        ['The server ignores .htaccess', 'The installer notices, says so and stops, before anything is written. Ask your host to allow <code>.htaccess</code> overrides, or point the domain at <code>public/</code>.'],
        ['The installer can\'t delete itself', 'It tells you. Delete <code>public/install.php</code> by hand, then log in at <code>/admin</code>.'],
    ])),
    $doc('updating', 'Updating', 'From the admin, with a backup first and a way back.', [
        $picture('32-updates-available.png'),
        $text('Look for a newer version', $p('Open <strong>Updates</strong> in the admin. Boxlet asks GitHub for its releases, and only when you press the button. It downloads the one you pick and checks the file against the checksum GitHub publishes.')),
        $text('Or upload the ZIP yourself', $p('On a server that can\'t reach GitHub, upload the release ZIP on the same screen.')),
        $text('Before anything changes', $p('Boxlet makes a backup of the whole site. Visitors see a short “being updated” page while it runs. Your pictures, settings and uploads stay where they are, and so do your own lines in <code>.htaccess</code>.')),
        $text('Roll back', $p('If something is wrong afterwards, <strong>Roll back</strong> puts the previous version back, together with the site as it was before the update.')),
        $picture('33-updates-roll-back.png'),
    ]),
    $doc('backups', 'Backups', 'The whole site in one ZIP.', [
        $picture('34-backups.png'),
        $text('What\'s inside', $p('Pages, settings, messages, every uploaded file and every picture size.')),
        $text('Where it\'s kept', $p('In <code>storage/backups</code>, on the same server. Download one now and then, and keep it somewhere else.')),
        $text('Restoring', $p('Restore a backup from the same screen, with one button. A backup of the site as it is is made first, so a restore can be undone too.', 'To bring one back, even onto a fresh install, put the downloaded file in <code>storage/backups</code> and it appears in the list.')),
    ]),
    $doc('locked-out', 'Locked out?', 'Both ways back in need only FTP or your host\'s file manager.', [
        $text('Forgot your password?', $p(
            'Use the link on the login page. It emails you a link that works for an hour, when the site can send mail.',
            'If it can\'t, upload a file named <code>storage/reset-password</code> whose only line is the new password, 12 characters or more. The next visit to the login page sets it and deletes the file.',
        )),
        $text('Lost the phone with your two-step login?', $p('Upload an empty file named <code>storage/disable-2fa</code>. Your next login switches two-step login off and deletes the file.')),
    ]),
    [
        'path' => 'get',
        'title' => 'Download',
        'description' => 'Boxlet is MIT licensed. One ZIP, from GitHub.',
        'sections' => [
            ['style' => [], 'blocks' => [
                $hero('Download Boxlet', ''),
                $text('', $p('Version {{snippet:version}}, a release candidate for v0.1. MIT licensed.')),
            ]],
            $one($cta('Get the ZIP from GitHub', 'Checked against the checksum GitHub publishes when you update from the admin.', 'Download from GitHub', 'https://github.com/BoxletCMS/Boxlet-CMS/releases/latest', 'beside'), ['surface' => 'plain']),
            $one(['stats', ['heading' => '', 'items' => [
                ['value' => 'PHP 8.1', 'label' => 'or newer'],
                ['value' => 'MySQL', 'label' => 'MariaDB or SQLite'],
                ['value' => 'Apache', 'label' => 'or nginx'],
            ]], 'three', []]),
            $one($text('Then', $ol(
                'Unpack the ZIP.',
                'Upload what\'s inside its <code>boxlet/</code> folder.',
                'Open <code>/install.php</code> on your site.',
            ) . $p('<a href="site:docs/installing">Full install guide</a>'))),
            $one($cta('Not sure yet?', 'Look around the demo first.', 'Open the demo', DEMO)),
        ],
    ],
];
