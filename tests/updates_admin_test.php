<?php

use App\Core\Container;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Update\Releases;
use App\Modules\Update\Upgrade;

// The Updates screen (PLAN.md D-140), through the router: a newer release found on GitHub,
// downloaded, installed after a backup, and rolled back. GitHub is a list the test writes,
// and the site updated is upgrade_test.php's made-up one: the checkout's own code is never
// the code swapped. oldSite() and newPackage() are in upgrade_test.php.

/**
 * A container set up for the made-up site at $root: running v0.1.0, updating that folder,
 * and "GitHub" answering from $releases with the package at $package.
 *
 * @param list<array<string, mixed>> $releases
 */
function updatesSite(string $root, array $releases, string $package): Closure
{
    return static function (Container $container) use ($root, $releases, $package): void {
        $container->set('version', static fn () => trim((string) file_get_contents($root . '/VERSION')));
        $container->set('upgrade', static fn (Container $c) => new Upgrade($c->get('db'), $root, $root . '/storage', $root . '/public/cache', $root . '/public'));
        $container->set('releases', static fn () => new Releases(
            static fn (string $url): string => json_encode($releases, JSON_THROW_ON_ERROR),
            static function (string $url, string $target) use ($package): void {
                copy($package, $target);
            },
        ));
    };
}

/** @param array<string, mixed> $body */
function updatesPost(string $path, array $body, Closure $site): Response
{
    return dispatch($path, null, 'POST', ['_csrf' => (new Session())->csrfToken()] + $body, '203.0.113.10', $site);
}

test('a newer release is found, installed after a backup, and rolled back', function () {
    [$root, $db] = oldSite();
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    removeTree((string) TestSite::$env['STORAGE_PATH'] . '/backups');

    $package = tmpPath('release.zip');
    newPackage($package, 'v0.2.0');
    $site = updatesSite($root, [
        ['tag_name' => 'v0.3.0', 'prerelease' => false, 'draft' => true, 'assets' => []],
        ['tag_name' => 'v0.2.0', 'prerelease' => false, 'published_at' => '2026-10-01T10:00:00Z', 'html_url' => 'https://github.com/BoxletCMS/Boxlet-CMS/releases/tag/v0.2.0',
            'assets' => [['name' => 'boxlet-v0.2.0.zip', 'browser_download_url' => 'https://github.com/BoxletCMS/Boxlet-CMS/releases/download/v0.2.0/boxlet-v0.2.0.zip',
                'digest' => 'sha256:' . hash_file('sha256', $package)]]],
        ['tag_name' => 'v0.1.0', 'prerelease' => false, 'assets' => []],
    ], $package);

    $screen = dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', $site);
    assertEquals(200, $screen->status, 'the screen');
    assertContains(t('updates.running', ['version' => 'v0.1.0']), $screen->body, 'the version running');

    assertRedirectedTo('/admin/updates', updatesPost('/admin/updates/check', [], $site));
    $found = dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', $site)->body;
    assertContains(e(t('updates.install', ['version' => 'v0.2.0'])), $found, 'offered v0.2.0, not the draft');

    assertRedirectedTo('/admin/updates#progress', updatesPost('/admin/updates/github', [], $site));
    $told = null;
    for ($presses = 0; $presses < 300; $presses++) {
        updatesPost('/admin/updates/step', [], $site);
        $told = $_SESSION['flash'] ?? $told;
        if (!str_contains(dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', $site)->body, 'data-auto-continue')) {
            break;
        }
    }
    assertEquals(t('updates.done', ['version' => 'v0.2.0']), $told, 'told');
    assertEquals("v0.2.0\n", (string) file_get_contents($root . '/VERSION'), 'the site runs v0.2.0');
    $db->query('SELECT COUNT(*) FROM upgrade_probe'); // the release's migration ran
    $backups = glob((string) TestSite::$env['STORAGE_PATH'] . '/backups/*-update.zip') ?: [];
    assertEquals(1, count($backups), 'a backup made before the update');

    $after = dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', $site)->body;
    assertContains(e(t('updates.last_done', ['from' => 'v0.1.0', 'version' => 'v0.2.0'])), $after, 'the last update');
    assertContains(e(t('updates.htaccess_kept', ['file' => '.htaccess'])), $after, 'the kept .htaccess is told');

    assertRedirectedTo('/admin/backups#progress', updatesPost('/admin/updates/roll-back', [], $site));
    assertEquals("v0.1.0\n", (string) file_get_contents($root . '/VERSION'), 'the old code is back');
    for ($presses = 0; $presses < 300 && str_contains(dispatch('/admin/backups')->body, 'data-auto-continue'); $presses++) {
        adminPost('/admin/backups/step', []);
    }
    $tables = array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
    assertTrue(!in_array('upgrade_probe', $tables, true), 'the database is not back as it was before the update');
    removeTree($root);
    removeTree((string) TestSite::$env['STORAGE_PATH'] . '/backups');
});

test('an uploaded ZIP that is not a newer release is refused, and nothing begins', function () {
    [$root, $db] = oldSite();
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    $site = updatesSite($root, [], tmpPath('none.zip'));

    // No file at all.
    assertRedirectedTo('/admin/updates#progress', updatesPost('/admin/updates/upload', [], $site));
    assertEquals(t('updates.upload_failed'), $_SESSION['flash'] ?? null, 'told');
    assertTrue(!is_file($root . '/storage/update/pending.json'), 'an update began');
    assertTrue(!str_contains(dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', $site)->body, 'data-auto-continue'), 'shown as under way');
    removeTree($root);
});

test('a development checkout is not offered updates', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/updates', null, 'GET', [], '203.0.113.10', static function (Container $c): void {
        $c->set('version', static fn () => 'development');
    })->body;
    assertContains(e(t('updates.running_development')), $body, 'says so');
    assertTrue(!str_contains($body, '/admin/updates/check'), 'offers a check anyway');
});
