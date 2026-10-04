<?php

use App\Core\Db;
use App\Modules\Backup\Backup;
use App\Modules\Backup\Backups;
use App\Modules\Backup\BackupTables;
use App\Modules\Backup\Restore;

// Making a backup (PLAN.md D-139): everything in it, read back through ZipArchive, which is
// what a restore reads it with; and made in many small steps, as a slow host makes it.

/**
 * A site to back up: its database, and folders standing in for storage/uploads, public/m and
 * .env, with a few files in them.
 *
 * @return array{Db, Backup, string} the database, the backup and the folder it all lives in
 */
function backupSite(string $driver): array
{
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createAdmin($db, 'owner@example.test', 'correct horse battery staple');
    for ($i = 1; $i <= 12; $i++) {
        createPage($db, 'en', "page-{$i}", "Page {$i} — čćž");
    }
    $dir = tmpPath('backup-site');
    removeTree($dir);
    mkdir($dir . '/uploads/2026', 0700, true);
    mkdir($dir . '/m/hero', 0700, true);
    file_put_contents($dir . '/uploads/2026/photo.jpg', random_bytes(40_000));
    file_put_contents($dir . '/uploads/.htaccess', 'Require all denied');
    file_put_contents($dir . '/m/hero/1-photo.avif', random_bytes(9_000));
    file_put_contents($dir . '/m/hero/1-photo.webp', random_bytes(11_000));
    file_put_contents($dir . '/.env', "APP_KEY=\"test-key\"\n");

    return [$db, new Backup($db, $dir, $dir . '/backups', $dir . '/uploads', $dir . '/m', $dir . '/.env', 'test-key'), $dir];
}

testBothDrivers('a backup holds every table\'s rows, the originals, their sizes and the settings', function (string $driver) {
    [$db, $backup, $dir] = backupSite($driver);

    $name = $backup->start('manual');
    $steps = 0;
    do {
        $result = $backup->step(0.0); // the least a step can be given: one piece each
        $steps++;
    } while (!$result['done'] && $steps < 500);

    assertTrue($result['done'], 'the backup never finished');
    assertTrue($steps > 5, "it took {$steps} steps, so the pieces were not small");
    assertEquals(null, $backup->running(), 'still running');
    assertEquals([], glob($dir . '/backups/*.{json,part,work,directory}', GLOB_BRACE) ?: [], 'work left behind');

    $read = zipContents($dir . '/backups/' . $name . '.zip');
    $manifest = json_decode($read['manifest.json'], true);
    assertEquals('manual', $manifest['kind'], 'kind');
    assertEquals($driver, $manifest['driver'], 'driver');
    assertEquals(Backup::fingerprint('test-key'), $manifest['key'], 'key print');
    assertTrue(!str_contains($read['manifest.json'], 'test-key'), 'the key itself is in the manifest');

    foreach (BackupTables::all($db) as $table) {
        $rows = array_values(array_filter(explode("\n", $read["database/{$table}.ndjson"] ?? fail("no {$table}"))));
        $count = (int) ($db->one("SELECT COUNT(*) AS n FROM {$table}")['n'] ?? -1);
        assertEquals($count, count($rows), "{$table} rows");
        assertEquals($count, (int) ($manifest['rows'][$table] ?? 0), "{$table} in the manifest");
    }
    foreach (BackupTables::LEFT_OUT as $table) {
        assertTrue(!isset($read["database/{$table}.ndjson"]), "{$table} is in the backup");
    }
    $titles = array_map(static fn (string $l): string => (string) json_decode($l, true)['title'], array_filter(explode("\n", $read['database/pages.ndjson'])));
    assertTrue(in_array('Page 12 — čćž', $titles, true), 'a page title came back different');

    assertTrue($read['storage/uploads/2026/photo.jpg'] === file_get_contents($dir . '/uploads/2026/photo.jpg'), 'the original');
    assertTrue($read['public/m/hero/1-photo.avif'] === file_get_contents($dir . '/m/hero/1-photo.avif'), 'a size');
    assertTrue(isset($read['public/m/hero/1-photo.webp']), 'the other size');
    assertTrue(!isset($read['storage/uploads/.htaccess']), 'a dot file');
    assertEquals("APP_KEY=\"test-key\"\n", $read['.env'], '.env');
    removeTree($dir);
});

test('a step killed half-way costs what it wrote since its checkpoint, not the backup', function () {
    [, $backup, $dir] = backupSite('sqlite');
    $name = $backup->start('manual');
    $backup->step(0.0);
    $backup->step(0.0);

    // What a request killed mid-write leaves: bytes past the checkpoint in both files.
    file_put_contents($dir . '/backups/' . $name . '.zip.part', random_bytes(5000), FILE_APPEND);
    file_put_contents($dir . '/backups/' . $name . '.zip.part.directory', random_bytes(300), FILE_APPEND);

    $steps = 0;
    do {
        $result = $backup->step(null);
    } while (!$result['done'] && ++$steps < 50);

    $zip = new ZipArchive();
    assertEquals(true, $zip->open($dir . '/backups/' . $name . '.zip', ZipArchive::CHECKCONS), 'the archive opens whole');
    assertTrue($zip->getFromName('manifest.json') !== false, 'manifest');
    $zip->close();
    removeTree($dir);
});

test('starting a backup while one is unfinished carries on with that one', function () {
    [, $backup, $dir] = backupSite('sqlite');
    $first = $backup->start('manual');
    $backup->step(0.0);
    assertEquals($first, $backup->start('update'), 'a second backup was begun');
    removeTree($dir);
});

/** A Restore over backupSite()'s folders, with this site's key. */
function restorer(Db $db, string $dir, string $key = 'test-key'): Restore
{
    foreach (['cache', 'public', 'storage'] as $folder) {
        if (!is_dir($dir . '/' . $folder)) {
            mkdir($dir . '/' . $folder, 0700, true);
        }
    }

    return new Restore($db, dirname(__DIR__), $dir . '/backups', $dir . '/uploads', $dir . '/m', $dir . '/.env', $key, $dir . '/storage', $dir . '/cache', $dir . '/public');
}

/** Runs $step until it says it is done, and returns how many steps that took. */
function stepToEnd(Closure $step): int
{
    $steps = 0;
    do {
        $result = $step();
        $steps++;
    } while (!$result['done'] && $steps < 2000);
    assertTrue($result['done'], 'never finished');

    return $steps;
}

/**
 * Every row of every table a backup holds, as the database reads it now.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function everyRow(Db $db): array
{
    $all = [];
    foreach (BackupTables::all($db) as $table) {
        $rows = $db->all('SELECT * FROM ' . BackupTables::quote($db, $table));
        $rows = array_map(static fn (array $r): array => array_map(static fn ($v) => $v === null ? null : (string) $v, $r), $rows);
        usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));
        $all[$table] = $rows;
    }
    ksort($all);

    return $all;
}

testBothDrivers('a restore brings back every row and file as the backup had them, in small steps', function (string $driver) {
    [$db, $backup, $dir] = backupSite($driver);
    $before = everyRow($db);
    $name = $backup->start('manual');
    stepToEnd(static fn () => $backup->step(null));
    $original = (string) file_get_contents($dir . '/uploads/2026/photo.jpg');

    // The site changes after the backup: pages go and come, a picture is replaced, a file added.
    $db->query("DELETE FROM pages WHERE slug = 'page-3'");
    createPage($db, 'en', 'after-the-backup', 'After the backup');
    $db->query("UPDATE pages SET title = 'Changed' WHERE slug = 'page-5'");
    file_put_contents($dir . '/uploads/2026/photo.jpg', 'replaced');
    file_put_contents($dir . '/m/hero/2-new.avif', 'new since');

    $restore = restorer($db, $dir);
    assertEquals(null, $restore->problem($name), 'a problem with its own backup');
    $restore->start($name);
    assertTrue((new App\Modules\Update\Maintenance($dir . '/storage'))->isOn(), 'maintenance is off while restoring');
    // After the first step, however short, there is an account to log in with.
    $restore->step(0.0);
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM admin')['n'] ?? 0), 'admins after the first step');
    $steps = 1 + stepToEnd(static fn () => $restore->step(0.0));

    assertTrue($steps > 3, "{$steps} steps");
    $after = everyRow($db);
    // The rows a restore writes of its own: finish() compiles the design's stylesheet and
    // records its file name and the code that made it (D-189), which this test site, never
    // rendered, did not have yet.
    $after['settings'] = array_values(array_filter($after['settings'], static fn (array $r): bool => !in_array($r['key'], ['tokens_css', 'tokens_code'], true)));
    assertEquals(array_keys($before), array_keys($after), 'tables');
    foreach ($before as $table => $rows) {
        assertEquals(count($rows), count($after[$table]), "{$table}: how many rows");
        assertEquals($rows, $after[$table], "{$table}: the rows");
    }
    assertTrue(file_get_contents($dir . '/uploads/2026/photo.jpg') === $original, 'the original came back different');
    assertTrue(!is_file($dir . '/m/hero/2-new.avif'), 'a file made after the backup is still there');
    assertTrue(is_file($dir . '/uploads/.htaccess'), 'the uploads guard went');
    assertTrue(!(new App\Modules\Update\Maintenance($dir . '/storage'))->isOn(), 'maintenance is left on');
    assertTrue(is_file($dir . '/public/sitemap.xml'), 'no sitemap');
    assertEquals(false, $restore->running(), 'still running');
    assertEquals([], (new App\Core\Migrator($db, dirname(__DIR__) . '/migrations'))->pending(), 'migrations pending');
    removeTree($dir);
});

test('a backup made on MySQL restores on SQLite', function () {
    [$mysql, $backup, $dir] = backupSite('mysql');
    $name = $backup->start('manual');
    stepToEnd(static fn () => $backup->step(null));
    $titles = array_column($mysql->all('SELECT title FROM pages ORDER BY id'), 'title');

    $sqlite = freshDatabase('sqlite');
    (new App\Core\Migrator($sqlite, dirname(__DIR__) . '/migrations'))->migrate();
    $restore = restorer($sqlite, $dir);
    $restore->start($name);
    stepToEnd(static fn () => $restore->step(null));

    assertEquals($titles, array_column($sqlite->all('SELECT title FROM pages ORDER BY id'), 'title'), 'titles');
    removeTree($dir);
});

test('a restore onto a site with another key brings the backup\'s key, and nothing else, into .env', function () {
    [$db, $backup, $dir] = backupSite('sqlite');
    $name = $backup->start('manual');
    stepToEnd(static fn () => $backup->step(null));
    file_put_contents($dir . '/.env', "DB_DRIVER=\"sqlite\"\nAPP_KEY=\"other-key\"\nAPP_DEBUG=\"false\"\n");

    $restore = restorer($db, $dir, 'other-key');
    $restore->start($name);
    stepToEnd(static fn () => $restore->step(null));

    assertEquals("DB_DRIVER=\"sqlite\"\nAPP_KEY=\"test-key\"\nAPP_DEBUG=\"false\"\n", (string) file_get_contents($dir . '/.env'), '.env');
    removeTree($dir);
});

test('a backup from a newer Boxlet, or one that is not a backup, is refused before anything is touched', function () {
    [$db, , $dir] = backupSite('sqlite');
    mkdir($dir . '/backups', 0700, true);
    $future = new App\Support\ZipWriter($dir . '/backups/2026-01-01-000000-manual.zip');
    $future->addString('manifest.json', json_encode(['boxlet' => 1, 'version' => 'v9.0.0', 'migrations' => ['0001_initial.sql', '9999_future.sql'], 'rows' => []]) ?: '');
    $future->finish();
    file_put_contents($dir . '/backups/2026-01-02-000000-manual.zip', 'not a zip');

    $restore = restorer($db, $dir);
    assertContains('newer Boxlet (v9.0.0)', (string) $restore->problem('2026-01-01-000000-manual'), 'newer');
    assertEquals(t('backups.unreadable'), $restore->problem('2026-01-02-000000-manual'), 'not a backup');
    assertEquals(t('backups.not_found'), $restore->problem('../../etc/passwd'), 'a path');
    assertEquals(t('backups.not_found'), $restore->problem('2026-01-01-000000-manual/../x'), 'a path in a name');
    assertThrows(static fn () => $restore->start('2026-01-01-000000-manual'), 'newer Boxlet');
    assertTrue(!$restore->running(), 'a refused restore began');
    removeTree($dir);
});

test('a backup from an older version restores, and the migrations it lacks run after its rows are in', function () {
    $db = freshDatabase('sqlite');
    $migrator = new App\Core\Migrator($db, dirname(__DIR__) . '/migrations');
    $older = array_slice($migrator->available(), 0, 8); // a site eight migrations old
    $migrator->migrate($older);
    $db->query("INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES ('en', 'English', 1, 0, 1)");
    $dir = tmpPath('backup-site');
    removeTree($dir);
    mkdir($dir, 0700, true);
    $backup = new Backup($db, $dir, $dir . '/backups', $dir . '/uploads', $dir . '/m', $dir . '/.env', 'test-key');
    $name = $backup->start('manual');
    stepToEnd(static fn () => $backup->step(null));
    assertEquals($older, (new Backups($dir . '/backups'))->manifest($name)['migrations'], 'the manifest');

    $current = freshDatabase('sqlite');
    (new App\Core\Migrator($current, dirname(__DIR__) . '/migrations'))->migrate();
    $restore = restorer($current, $dir);
    $restore->start($name);
    stepToEnd(static fn () => $restore->step(null));

    assertEquals([], (new App\Core\Migrator($current, dirname(__DIR__) . '/migrations'))->pending(), 'pending after the restore');
    assertEquals('English', (string) ($current->one("SELECT label FROM locales WHERE code = 'en'")['label'] ?? ''), 'the row it held');
    removeTree($dir);
});
