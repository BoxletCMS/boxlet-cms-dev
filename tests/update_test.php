<?php

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Update\Update;

// Updating an existing install (PLAN.md D-019).
//
// The case is a site whose code carries a migration its database has not applied. These
// build that state by writing an extra .sql file into a temporary migrations directory,
// so nothing depends on the project's real migration set or on what it will contain
// later.

/**
 * A migrations directory holding copies of the real ones, plus any extra files given.
 *
 * @param array<string, string> $extra filename => SQL
 */
function migrationsDir(array $extra = []): string
{
    $dir = tmpPath('migrations-' . substr(md5(serialize($extra) . random_int(0, PHP_INT_MAX)), 0, 8));
    removeTree($dir);
    mkdir($dir, 0700, true);
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
        copy($file, $dir . '/' . basename($file));
    }
    foreach ($extra as $name => $sql) {
        file_put_contents($dir . '/' . $name, $sql);
    }

    return $dir;
}

function updateFor(Db $db, string $migrations, ?string $sqlitePath = null): Update
{
    $storage = tmpPath('update-storage');
    removeTree($storage);
    mkdir($storage, 0700, true);

    return new Update($db, $migrations, $storage, $sqlitePath);
}

testBothDrivers('a migration the database has not applied is pending', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);

    // Nothing extra: an installed site is up to date.
    assertEquals([], updateFor($db, migrationsDir())->pending(), 'an up-to-date site reports pending work');

    $dir = migrationsDir(['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}}\n);\n"]);
    assertEquals(['9001_later.sql'], updateFor($db, $dir)->pending(), 'the new file is not reported');
});

testBothDrivers('pressing the button applies the migration and records it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $dir = migrationsDir(['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}},\n    note VARCHAR(50) NULL\n);\n"]);
    $update = updateFor($db, $dir);

    assertEquals(['9001_later.sql'], $update->run(), 'the files it says it applied');
    assertEquals([], $update->pending(), 'still pending after a successful run');

    $recorded = array_column($db->all('SELECT filename FROM migrations'), 'filename');
    assertTrue(in_array('9001_later.sql', $recorded, true), 'the file was not recorded');

    // The table really exists: the point of the exercise.
    $db->query('INSERT INTO later_thing (note) VALUES (?)', ['works']);
    assertEquals('works', (string) ($db->one('SELECT note FROM later_thing')['note'] ?? ''), 'the new table');
});

testBothDrivers('a failing migration is named, and what ran before it stays recorded', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $dir = migrationsDir([
        '9001_fine.sql' => "CREATE TABLE fine_thing (\n    id {{pk}}\n);\n",
        '9002_broken.sql' => "CREATE TABLE broken_thing (\n    id {{pk}},\n    oops NOT A TYPE\n);\n",
    ]);
    $update = updateFor($db, $dir);

    assertThrows(static fn () => $update->run(), '9002_broken.sql');

    $recorded = array_column($db->all('SELECT filename FROM migrations'), 'filename');
    assertTrue(in_array('9001_fine.sql', $recorded, true), 'the file that succeeded was not recorded');
    assertTrue(!in_array('9002_broken.sql', $recorded, true), 'the file that failed was recorded anyway');

    // Retrying continues from the failure rather than repeating what worked.
    assertEquals(['9002_broken.sql'], $update->pending(), 'what is left to do after the failure');
});

testBothDrivers('a second run while one is in progress is refused', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $dir = migrationsDir(['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}}\n);\n"]);

    $storage = tmpPath('update-lock-storage');
    removeTree($storage);
    mkdir($storage, 0700, true);
    $update = new Update($db, $dir, $storage);

    // The lock a run in progress holds.
    file_put_contents($storage . '/update.lock', '');
    assertThrows(static fn () => $update->run(), t('update.locked'));
    assertEquals(['9001_later.sql'], $update->pending(), 'the refused run applied the migration anyway');

    // Released, the same object runs.
    unlink($storage . '/update.lock');
    assertEquals(['9001_later.sql'], $update->run(), 'the run after the lock was released');
    assertTrue(!is_file($storage . '/update.lock'), 'the lock was left behind after a successful run');
});

test('a SQLite database is copied into storage/backups before anything runs', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    $path = (string) TestSite::$env['DB_PATH'];
    $dir = migrationsDir(['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}}\n);\n"]);

    $storage = tmpPath('update-backup-storage');
    removeTree($storage);
    mkdir($storage, 0700, true);
    $update = new Update($db, $dir, $storage, $path);

    // What the backup has to be a copy of: the database as it stood BEFORE the run.
    $before = (string) file_get_contents($path);
    $update->run();

    $backups = glob($storage . '/backups/*.sqlite') ?: [];
    assertEquals(1, count($backups), 'how many backups were written');
    assertEquals($before, (string) file_get_contents($backups[0]), 'the backup is not the database as it was before the run');

    // And the live database did move on, so the comparison above means something.
    assertTrue($before !== (string) file_get_contents($path), 'the migration did not change the database at all');
});

test('MySQL is not copied: the screen asks for a host backup instead', function () {
    $db = new Db('mysql', 'mysql:host=127.0.0.1;dbname=nothing');
    $storage = tmpPath('update-mysql-storage');
    removeTree($storage);
    mkdir($storage, 0700, true);

    // No path is passed for MySQL, and backup() returns without touching the disk.
    assertEquals(null, (new Update($db, migrationsDir(), $storage))->backup(), 'MySQL wrote a backup');
    assertTrue(!is_dir($storage . '/backups'), 'a backups directory was created for MySQL');
});

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

test('detection costs nothing once the marker matches', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    $dir = migrationsDir();
    $storage = tmpPath('update-marker-storage');
    removeTree($storage);
    mkdir($storage, 0700, true);
    $update = new Update($db, $dir, $storage);

    assertEquals([], $update->pending(), 'an up-to-date site');
    assertTrue(is_file($storage . '/migrations.state'), 'the marker was not written');

    // With the marker in place the answer comes from the file. Proved by breaking the
    // database: a pending() that still queried would fail rather than answer.
    $marker = (string) file_get_contents($storage . '/migrations.state');
    $broken = new Update(new Db('sqlite', 'sqlite:/nonexistent/path.sqlite'), $dir, $storage);
    assertEquals([], $broken->pending(), 'it queried the database despite the marker');

    // A new file changes the directory, so the marker no longer matches and it asks again.
    file_put_contents($dir . '/9001_later.sql', "CREATE TABLE later_thing (\n    id {{pk}}\n);\n");
    assertEquals(['9001_later.sql'], $update->pending(), 'a new migration was missed');

    // The marker is both halves, and the count is the half that catches this: a file
    // whose name sorts BELOW the newest leaves "newest filename" unchanged, so a marker
    // of that alone would report the site up to date. Filenames are NNNN_name.sql — the
    // Migrator rejects anything else — so a back-ported migration takes a free number
    // below the highest, not a suffixed one.
    assertTrue(
        str_starts_with($marker, (string) count(glob(dirname(__DIR__) . '/migrations/*.sql') ?: [])),
        'the marker does not begin with the file count',
    );

    // 0006_… sorts BELOW the newest real migration, so the "newest filename" half of the
    // marker does not move; only the count does. A marker made of the filename alone
    // would report this site up to date while a migration waited.
    $clean = migrationsDir();
    $store = tmpPath('update-count-storage');
    removeTree($store);
    mkdir($store, 0700, true);
    $counted = new Update($db, $clean, $store);

    assertEquals([], $counted->pending(), 'the copy of the real migrations is not up to date');
    $before = (string) file_get_contents($store . '/migrations.state');

    file_put_contents($clean . '/0006_backported.sql', "CREATE TABLE backported_thing (\n    id {{pk}}\n);\n");
    $after = new Update($db, $clean, $store);

    assertEquals(['0006_backported.sql'], $after->pending(), 'a migration added below the newest filename was missed');

    // And the newest filename genuinely did not move, so the count is what caught it.
    $files = array_map('basename', glob($clean . '/*.sql') ?: []);
    sort($files);
    $newest = (string) end($files);
    assertTrue(str_ends_with($before, $newest), "the marker does not end with the newest filename ({$newest})");
    assertTrue(!str_starts_with($newest, '0006_'), 'the added file became the newest, so this proves nothing');
});
