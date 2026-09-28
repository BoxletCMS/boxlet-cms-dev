<?php

// The Backups screen (PLAN.md D-139), through the router as the admin uses it: make one,
// see it listed, download it, restore it (which makes a backup of the site first), delete
// it. Every path here is the test's own (fixtures.php): the checkout's public/m and .env
// are never read or written.

/**
 * Presses Continue until nothing is under way, looking at the screen after each press as the
 * browser does, and returns what the last press told the owner: the screen shows a message
 * once, so it is read before the screen takes it.
 */
function continueToEnd(): ?string
{
    $presses = 0;
    do {
        assertRedirectedTo('/admin/backups#progress', adminPost('/admin/backups/step', []));
        $told = $_SESSION['flash'] ?? null;
        $presses++;
        $screen = dispatch('/admin/backups');
    } while (str_contains($screen->body, 'data-auto-continue') && $presses < 200);
    assertTrue(!str_contains($screen->body, 'data-auto-continue'), 'still under way after 200 presses');

    return is_string($told) ? $told : null;
}

/**
 * The names of the backups the screen lists, newest first.
 *
 * @return list<string>
 */
function listedBackups(): array
{
    preg_match_all('~/admin/backups/(\d{4}-\d{2}-\d{2}-\d{6}-[a-z]+)/download~', dispatch('/admin/backups')->body, $found);

    return array_values(array_unique($found[1]));
}

testBothDrivers('the admin makes a backup, downloads it, restores it and deletes it', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'kept', 'Kept');
    $storage = (string) TestSite::$env['STORAGE_PATH'];
    removeTree($storage . '/backups');
    file_put_contents((string) TestSite::$env['ENV_PATH'], "APP_KEY=\"test-key-not-a-secret\"\n");

    $empty = dispatch('/admin/backups');
    assertEquals(200, $empty->status, 'the screen');
    assertContains(t('backups.none'), $empty->body, 'no backups yet');
    assertContains(t('backups.make'), $empty->body, 'the button');

    assertRedirectedTo('/admin/backups#progress', adminPost('/admin/backups', []));
    assertEquals(t('backups.made'), continueToEnd(), 'told');
    $made = listedBackups();
    assertEquals(1, count($made), 'listed');

    $download = dispatch('/admin/backups/' . $made[0] . '/download');
    assertEquals(200, $download->status, 'download');
    assertEquals('application/zip', $download->headers['Content-Type'] ?? null, 'as a ZIP');
    assertContains('attachment', (string) ($download->headers['Content-Disposition'] ?? ''), 'to save');

    // The site changes, then the backup is put back.
    $db->query("UPDATE pages SET title = 'Changed since' WHERE slug = 'kept'");
    $confirm = dispatch('/admin/backups/' . $made[0] . '/restore');
    assertEquals(200, $confirm->status, 'the confirmation');
    assertContains(t('backups.restore_step1'), $confirm->body, 'says a backup comes first');
    assertRedirectedTo('/admin/backups#progress', adminPost('/admin/backups/' . $made[0] . '/restore', []));
    assertEquals(t('backups.restored'), continueToEnd(), 'told');
    assertEquals('Kept', (string) ($db->one("SELECT title FROM pages WHERE slug = 'kept'")['title'] ?? ''), 'the page as the backup had it');
    $after = listedBackups();
    assertEquals(2, count($after), 'the backup made before the restore is listed too');
    assertTrue(str_ends_with($after[0], '-restore'), 'the newest is the one made before the restore');
    assertTrue(!is_file($storage . '/maintenance.flag'), 'the site is left closed');
    assertEquals(200, dispatch('/admin/backups')->status, 'the admin is still logged in');

    assertRedirectedTo('/admin/backups', adminPost('/admin/backups/' . $made[0] . '/delete', []));
    assertEquals([$after[0]], listedBackups(), 'deleted');
    removeTree($storage . '/backups');
});

test('only a backup\'s own name reaches the screen\'s routes', function () {
    adminSite('sqlite');
    foreach (['/admin/backups/..%2F..%2Fsecret/download', '/admin/backups/2026-01-01-000000-other/download', '/admin/backups/x/restore'] as $path) {
        assertEquals(404, dispatch($path)->status, $path);
    }
    assertRedirectedTo('/admin/backups', dispatch('/admin/backups/2026-01-01-000000-manual/download'));
});

test('the backups screen is the admin\'s only', function () {
    adminSite('sqlite');
    unset($_SESSION['admin_id']);
    assertRedirectedTo('/admin/login', dispatch('/admin/backups'));
    assertRedirectedTo('/admin/login', dispatch('/admin/backups/2026-01-01-000000-manual/download'));
});
