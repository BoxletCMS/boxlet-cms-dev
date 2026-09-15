<?php

use App\Core\Blocks;
use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Pages\Page;
use Dotenv\Dotenv;

// Database and site fixtures. Every test builds the state it needs from scratch:
// SQLite files live in tests/tmp/, MySQL uses the dedicated test database from
// .env.test (or the environment) and has every table dropped first.

function tmpPath(string $name): string
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    return $dir . '/' . $name;
}

/**
 * A TEST_* setting from .env.test, falling back to the environment variable, so CI
 * can supply its own.
 */
function testSetting(string $key): string
{
    static $file = null;
    if ($file === null) {
        $path = dirname(__DIR__) . '/.env.test';
        $file = is_file($path) ? Dotenv::parse((string) file_get_contents($path)) : [];
    }
    $value = (string) ($file[$key] ?? '');
    if ($value === '') {
        $value = (string) getenv($key);
    }

    return $value;
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string}
 */
function mysqlTestConfig(): array
{
    $host = testSetting('TEST_MYSQL_HOST');
    if ($host === '') {
        skip('MySQL not configured: set TEST_MYSQL_* in .env.test or the environment (see .env.test.example)');
    }

    return [
        'driver' => 'mysql',
        'host' => $host,
        'port' => (int) (testSetting('TEST_MYSQL_PORT') ?: '3306'),
        'database' => testSetting('TEST_MYSQL_DATABASE'),
        'username' => testSetting('TEST_MYSQL_USERNAME'),
        'password' => testSetting('TEST_MYSQL_PASSWORD'),
    ];
}

/**
 * An empty database: a new SQLite file, or the MySQL test database with every table
 * dropped.
 */
function freshDatabase(string $driver): Db
{
    if ($driver === 'sqlite') {
        $path = tmpPath('test.sqlite');
        if (is_file($path)) {
            unlink($path);
        }

        return new Db('sqlite', 'sqlite:' . $path);
    }

    $db = Db::fromConfig(mysqlTestConfig());
    // Foreign keys would otherwise dictate the drop order.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->all('SHOW TABLES') as $row) {
        $table = (string) array_values($row)[0];
        $db->query('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

    return $db;
}

function migratedDatabase(string $driver): Db
{
    $db = freshDatabase($driver);
    (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();

    return $db;
}

/**
 * An installed site for dispatch(): migrated database, exactly the given locales
 * enabled (the first is primary) and an install.lock. The test owns this state; none
 * of it comes from config or installer defaults.
 *
 * @param array<string, string> $locales code => label
 */
function installedSite(array $locales = ['en' => 'English', 'hr' => 'Hrvatski'], string $driver = 'sqlite'): Db
{
    $db = migratedDatabase($driver);
    $sort = 0;
    foreach ($locales as $code => $label) {
        $db->query(
            'INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES (?, ?, ?, ?, 1)',
            [(string) $code, $label, $sort === 0 ? 1 : 0, $sort],
        );
        $sort++;
    }

    $storage = tmpPath('storage');
    if (!is_dir($storage)) {
        mkdir($storage, 0700, true);
    }
    file_put_contents($storage . '/install.lock', "test\n");

    $database = $driver === 'sqlite'
        ? ['DB_DRIVER' => 'sqlite', 'DB_PATH' => tmpPath('test.sqlite')]
        : ['DB_DRIVER' => 'mysql'] + array_map('strval', array_combine(
            ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
            array_slice(array_values(mysqlTestConfig()), 1),
        ));
    TestSite::$env = $database + ['STORAGE_PATH' => $storage, 'APP_KEY' => 'test-key-not-a-secret'];

    return $db;
}

function createAdmin(Db $db, string $email, string $password): void
{
    $db->query(
        'INSERT INTO admin (email, password_hash, created_at) VALUES (?, ?, ?)',
        [$email, password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s')],
    );
}

/**
 * A page created through the model, with the given blocks, published unless told not to.
 *
 * @param list<array{type: string, content: array<string, mixed>, style?: array<string, string>, layout?: string}> $blocks
 */
function createPage(Db $db, string $locale, string $slug, string $title, bool $published = true, array $blocks = []): int
{
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $id = Page::create($db, $registry, $locale, $title, $slug, null, []);
    if ($blocks !== []) {
        $rows = [];
        foreach ($blocks as $block) {
            $rows[] = [
                'id' => null,
                'type' => $block['type'],
                'content' => $registry->normalize($block['type'], $block['content']),
                'style' => $block['style'] ?? [],
                'layout' => $registry->layout($block['type'], $block['layout'] ?? null),
            ];
        }
        Page::update($db, $id, $title, $slug, $rows);
    }
    if ($published) {
        Page::setStatus($db, $id, true);
    }

    return $id;
}

function removeTree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

/**
 * Destroys everything tests created: tests/tmp and every table in the MySQL test database.
 */
function cleanupTestState(): void
{
    removeTree(__DIR__ . '/tmp');
    if (testSetting('TEST_MYSQL_HOST') !== '') {
        freshDatabase('mysql');
    }
}
