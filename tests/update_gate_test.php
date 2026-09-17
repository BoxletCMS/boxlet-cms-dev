<?php

use App\Core\Container;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Update\Update;

// The update gate through the admin and the public site (PLAN.md D-019).
//
// Split out of update_test.php, which had grown past the 300-line limit. Nothing here
// changed in the split: the model-level cases — pending detection, the lock, the backup,
// the marker — stayed, and these, which go through a request, moved.
//
// The gate runs inside Router::dispatch(), so these exercise the real thing rather than a
// copy of its reasoning; public/index.php checks the same gate earlier, before the router
// is built at all.

// Through the admin and the public site. The gate runs inside Router::dispatch(), so
// these exercise the real thing rather than a copy of its reasoning; public/index.php
// checks the same gate earlier, before the router is built at all.
//
// A pending migration is created by pointing the container's Update at a migrations
// directory holding one extra file, which is what a site that has taken new code looks
// like before the button is pressed.

/**
 * Replaces the container's `update` with one that sees $extra as pending.
 *
 * dispatch() builds a fresh container per call, so this closure runs again on every
 * request. The migrations directory and the storage directory are therefore made ONCE,
 * here, and captured: making them inside the closure would give each request its own
 * marker file, and a test that pressed the button in one request and checked the result
 * in the next would be looking at two different installs.
 *
 * @param array<string, string> $extra filename => SQL
 */
function pendingUpdate(array $extra): Closure
{
    $dir = migrationsDir($extra);
    $storage = tmpPath('update-http-' . basename($dir));
    removeTree($storage);
    mkdir($storage, 0700, true);

    return static function (\App\Core\Container $container) use ($dir, $storage): void {
        $container->set('update', static fn (\App\Core\Container $c) => new Update($c->get('db'), $dir, $storage));
    };
}

/**
 * A POST as a logged-in admin, with the CSRF token, through a configured container.
 *
 * adminPost() exists for this but takes no configurator — it passes null — so a call
 * that handed it one would silently drop it and test the real update state instead of
 * the pending one.
 *
 * @param array<string, mixed> $body
 */
function adminPostConfigured(string $path, array $body, Closure $configure): Response
{
    return dispatch($path, null, 'POST', ['_csrf' => (new Session())->csrfToken()] + $body, '203.0.113.10', $configure);
}

/**
 * A GET through a configured container. dispatch()'s second argument configures the
 * ROUTER, which is built from the container and so cannot replace a service in it.
 */
function dispatchConfigured(string $path, Closure $configure): Response
{
    return dispatch($path, null, 'GET', [], '203.0.113.10', $configure);
}

const PENDING_SQL = ['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}}\n);\n"];

testBothDrivers('while an update is pending the public site answers 503, not a page', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');

    $response = dispatchConfigured('/about', pendingUpdate(PENDING_SQL));

    assertEquals(503, $response->status, 'status');
    assertEquals('120', $response->headers['Retry-After'] ?? null, 'Retry-After');
    assertContains(t('update.public.body'), $response->body, 'the being-updated page');
    // Not the page, and not a stack trace.
    assertTrue(!str_contains($response->body, 'About'), 'it rendered the page anyway');
});

testBothDrivers('while an update is pending every admin screen becomes the update screen', function (string $driver) {
    adminSite($driver);

    foreach (['/admin', '/admin/pages', '/admin/design'] as $path) {
        $response = dispatchConfigured($path, pendingUpdate(PENDING_SQL));
        assertEquals(302, $response->status, "{$path}: status");
        assertEquals('/admin/update', $response->headers['Location'] ?? null, "{$path}: where it goes");
    }

    // The two paths that stay reachable: logging in, and the screen itself.
    assertEquals(200, dispatchConfigured('/admin/update', pendingUpdate(PENDING_SQL))->status, '/admin/update');
    unset($_SESSION['admin_id']);
    assertEquals(200, dispatchConfigured('/admin/login', pendingUpdate(PENDING_SQL))->status, '/admin/login');
});

testBothDrivers('the update screen lists the pending files and offers one button', function (string $driver) {
    adminSite($driver);
    $body = dispatchConfigured('/admin/update', pendingUpdate(PENDING_SQL))->body;

    assertContains(t('update.title'), $body, 'the heading');
    assertContains('9001_later.sql', $body, 'the pending file');
    assertContains(t('update.run'), $body, 'the button');
    assertContains('name="_csrf"', $body, 'the CSRF token');
});

testBothDrivers('the button applies the migration; without a token nothing runs', function (string $driver) {
    $db = adminSite($driver);
    $configure = pendingUpdate(PENDING_SQL);

    // No CSRF token: refused, and nothing applied.
    $refused = dispatch('/admin/update', null, 'POST', [], '203.0.113.10', $configure);
    assertEquals(403, $refused->status, 'a POST without a token was accepted');
    $recorded = array_column($db->all('SELECT filename FROM migrations'), 'filename');
    assertTrue(!in_array('9001_later.sql', $recorded, true), 'it ran without a token');

    // With one: applied, recorded, and the site comes back.
    $applied = adminPostConfigured('/admin/update', [], $configure);
    assertEquals(302, $applied->status, 'status after pressing the button');
    $recorded = array_column($db->all('SELECT filename FROM migrations'), 'filename');
    assertTrue(in_array('9001_later.sql', $recorded, true), 'the migration was not recorded');
});
