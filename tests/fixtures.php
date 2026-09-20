<?php

use App\Core\Blocks;
use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Design\SectionStyle;
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
    TestSite::$env = $database + [
        'STORAGE_PATH' => $storage,
        'CACHE_PATH' => tmpPath('cache'),
        'PUBLIC_PATH' => tmpPath('public-root'),
        'APP_KEY' => 'test-key-not-a-secret',
    ];

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
 * The block registry, discovered once. Several tests and fixtures need one to save a
 * page, because saving resolves media references against what the blocks declare.
 */
function blockRegistry(): Blocks
{
    static $registry = null;

    return $registry ??= Blocks::discover(dirname(__DIR__) . '/app/Blocks');
}

/**
 * A page created through the model, with the given blocks, published unless told not to.
 *
 * @param list<array{type: string, content: array<string, mixed>, style?: array<string, string|int|null>, layout?: string}> $blocks
 */
function createPage(Db $db, string $locale, string $slug, string $title, bool $published = true, array $blocks = []): int
{
    $registry = blockRegistry();
    $id = Page::create($db, $registry, $locale, $title, $slug, null, []);
    if ($blocks !== []) {
        $rows = [];
        foreach ($blocks as $block) {
            $rows[] = [
                'id' => null,
                'type' => $block['type'],
                'content' => $registry->normalize($block['type'], $block['content']),
                'style' => SectionStyle::normalize($block['style'] ?? []),
                'layout' => $registry->layout($block['type'], $block['layout'] ?? null),
            ];
        }
        Page::update($db, $registry, $id, ['title' => $title, 'slug' => $slug, 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}'], $rows);
    }
    if ($published) {
        Page::setStatus($db, $id, true);
    }

    return $id;
}

/**
 * A picture in the library with exactly the variants named, and nothing else.
 *
 * No files are written: every test that uses this asserts on markup, and a variant is a
 * row in variants_json before it is anything else. The original is always 2400×1600, so a
 * test can tell a variant's recorded output size apart from the source's.
 *
 * @param array<string, array{width: int, height: int, formats: list<string>}> $variants preset => what was generated
 */
function storedPicture(Db $db, string $filename, array $variants, int $focalX = 50, int $focalY = 50): int
{
    static $unique = 0;
    $unique++;

    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status, focal_x, focal_y, variants_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $filename,
            $filename . '.jpg',
            'uploads/' . $filename . '.jpg',
            'image/jpeg',
            1000,
            2400,
            1600,
            'hash-' . $filename . '-' . $unique,
            '2026-01-01 00:00:00',
            $variants === [] ? 'incomplete' : 'complete',
            $focalX,
            $focalY,
            json_encode($variants, JSON_THROW_ON_ERROR),
        ],
    );

    return (int) $db->lastInsertId();
}

/**
 * The WHOLE Appearance screen as the browser posts it (PLAN.md D-059): the ten design
 * decisions, plus whatever this test is actually about.
 *
 * Design and Header & footer became one form, so a post that carries only half of it is
 * refused — every decision is validated, and one that is missing is not one to be guessed.
 * A test that means to change the header still has to send the design, exactly as a browser
 * does, and this is that boilerplate in one place.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function appearanceFields(array $fields = []): array
{
    $decisions = App\Modules\Design\Presets::get(App\Modules\Design\Presets::DEFAULT);

    return $fields + [
        'use_secondary' => $decisions['secondary'] !== '' ? '1' : '0',
        'secondary' => $decisions['secondary'] !== '' ? $decisions['secondary'] : '#000000',
    ] + $decisions;
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

/**
 * A mail transport that keeps what it is given instead of sending it (PLAN.md D-045), for
 * tests that need to see a message: dispatch()'s container configurator puts one in place
 * of the site's own.
 */
final class CapturingTransport extends Symfony\Component\Mailer\Transport\AbstractTransport
{
    /** @var list<Symfony\Component\Mime\Email> */
    public array $sent = [];

    public function __toString(): string
    {
        return 'capturing://';
    }

    protected function doSend(Symfony\Component\Mailer\SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if ($original instanceof Symfony\Component\Mime\Email) {
            $this->sent[] = $original;
        }
    }
}
