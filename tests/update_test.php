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

// Detection, not a request: this belongs with the model-level cases. It went out with
// the split by accident and failed there for want of the Db import — it builds a
// deliberately broken connection to prove the marker answers without querying.
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
