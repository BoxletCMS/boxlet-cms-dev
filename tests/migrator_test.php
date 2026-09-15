<?php

use App\Core\Migrator;

// Migrations are plain portable SQL plus exactly one token, {{pk}} (SPEC §5.0).

/**
 * @return list<string>
 */
function migrationFiles(): array
{
    $files = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql') ?: []);
    sort($files);

    return $files;
}

testBothDrivers('migrations run in filename order and are recorded', function (string $driver) {
    $db = freshDatabase($driver);
    $applied = (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();

    assertEquals(migrationFiles(), $applied, 'applied by this run');
    $recorded = array_column($db->all('SELECT filename FROM migrations ORDER BY filename'), 'filename');
    assertEquals(migrationFiles(), $recorded, 'migrations table');
});

testBothDrivers('running migrations twice is a no-op', function (string $driver) {
    $db = migratedDatabase($driver);

    assertEquals([], (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate(), 'second run');
    $count = (int) ($db->one('SELECT COUNT(*) AS n FROM migrations')['n'] ?? -1);
    assertEquals(count(migrationFiles()), $count, 'rows in migrations');
});

testBothDrivers('{{pk}} ids are assigned automatically and are never NULL', function (string $driver) {
    $db = migratedDatabase($driver);
    createAdmin($db, 'a@example.com', 'correct horse battery staple');
    createAdmin($db, 'b@example.com', 'correct horse battery staple');

    $ids = array_map('intval', array_column($db->all('SELECT id FROM admin ORDER BY email'), 'id'));
    assertEquals([1, 2], $ids, 'admin ids');
});

test('no migration declares an auto-increment id directly', function () {
    foreach (migrationFiles() as $file) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $file);
        // This form parses on SQLite and silently stores NULL ids.
        assertTrue(
            !preg_match('~INTEGER\s+AUTO_INCREMENT\s+PRIMARY\s+KEY~i', $sql),
            "{$file} contains INTEGER AUTO_INCREMENT PRIMARY KEY",
        );
        assertTrue(!preg_match('~AUTO_?INCREMENT~i', $sql), "{$file} declares auto-increment directly; use {{pk}}");
    }
});

test('the Migrator refuses a direct auto-increment clause', function () {
    assertThrows(
        fn () => Migrator::compile("CREATE TABLE t (id INTEGER AUTO_INCREMENT PRIMARY KEY);\n", 'sqlite', 'x.sql'),
        'declare auto-increment ids as {{pk}}',
    );
});

test('{{pk}} compiles to the primary key each driver needs', function () {
    $sql = "-- comment\nCREATE TABLE t (id {{pk}}, v VARCHAR(10));\nCREATE INDEX t_v ON t (v);\n";
    assertEquals(
        ['CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(10))', 'CREATE INDEX t_v ON t (v)'],
        Migrator::compile($sql, 'sqlite', 'x.sql'),
        'sqlite',
    );
    assertEquals(
        ['CREATE TABLE t (id INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))', 'CREATE INDEX t_v ON t (v)'],
        Migrator::compile($sql, 'mysql', 'x.sql'),
        'mysql',
    );
});

test('any token other than {{pk}} is fatal', function () {
    assertThrows(fn () => Migrator::compile('CREATE TABLE t (id {{id}});', 'mysql', 'x.sql'), 'unknown token {{id}}');
    assertThrows(fn () => Migrator::compile('CREATE TABLE t (id {{ pk }});', 'mysql', 'x.sql'), 'unknown token {{ pk }}');
    assertThrows(fn () => Migrator::compile('CREATE TABLE t (id {{pk);', 'mysql', 'x.sql'), 'unbalanced');
});

test('a bad token aborts the whole run before any file is applied', function () {
    $dir = tmpPath('bad-migrations');
    removeTree($dir);
    mkdir($dir, 0700, true);
    copy(dirname(__DIR__) . '/migrations/0001_migrations.sql', $dir . '/0001_migrations.sql');
    file_put_contents($dir . '/0002_bad.sql', "CREATE TABLE t (id {{uuid}});\n");
    $db = freshDatabase('sqlite');

    assertThrows(fn () => (new Migrator($db, $dir))->migrate(), 'unknown token {{uuid}}');
    assertThrows(fn () => $db->all('SELECT filename FROM migrations'), 'no such table');
});
