<?php

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Install\DatabaseSetup;
use App\Modules\Install\InstallController;
use App\Modules\Install\Installer;
use App\Modules\Install\Requirements;
use App\Support\Url;
use Dotenv\Dotenv;

// The installer, driven step by step without a web server. Each test gets its own
// storage, .env path and install.php copy under tests/tmp/install.

function installer(bool $rewriteWorks = true): InstallController
{
    $dir = tmpPath('install');
    removeTree($dir);
    mkdir($dir . '/storage', 0700, true);
    file_put_contents($dir . '/install.php', "<?php // test copy\n");
    Url::configure('', '');

    return new InstallController(
        dirname(__DIR__),
        $dir . '/storage',
        $dir . '/.env',
        $dir . '/install.php',
        new Session(),
        static fn (): bool => $rewriteWorks,
    );
}

function installGet(InstallController $installer): Response
{
    return $installer->handle(new Request('GET', '/install.php', '', [], [], []));
}

/**
 * @param array<string, string> $fields
 */
function installPost(InstallController $installer, array $fields): Response
{
    $body = ['_csrf' => (new Session())->csrfToken()] + $fields;

    return $installer->handle(new Request('POST', '/install.php', '', [], $body, []));
}

function installToken(): string
{
    return trim((string) file_get_contents(tmpPath('install/storage/install-token.txt')));
}

function assertAdvanced(Response $response, string $step): void
{
    if ($response->status !== 302) {
        preg_match('~role="alert">([^<]*)<~', $response->body, $error);
        fail(sprintf('%s: expected 302, got %d: %s', $step, $response->status, html_entity_decode($error[1] ?? '(no message)')));
    }
}

test('install.lock blocks the installer', function () {
    $installer = installer();
    file_put_contents(tmpPath('install/storage/install.lock'), "installed\n");

    $response = installGet($installer);
    assertEquals(403, $response->status, 'GET status');
    assertContains(e(t('install.locked.title')), $response->body, 'GET page');
    assertEquals(403, installPost($installer, ['token' => 'anything'])->status, 'POST status');
});

test('the first visit writes an install token to storage, never to the page', function () {
    $response = installGet(installer());
    $token = installToken();

    assertTrue((bool) preg_match('~^[0-9a-f]{32}$~', $token), 'token is 32 hex characters');
    assertTrue(!str_contains($response->body, $token), 'the page must not reveal the token');
    assertContains('install-token.txt', $response->body, 'the page says where to find it');
});

test('a wrong install token is rejected', function () {
    $installer = installer();
    installGet($installer);

    $response = installPost($installer, ['token' => str_repeat('0', 32)]);
    assertEquals(422, $response->status, 'status');
    assertContains(e(t('install.token.wrong')), $response->body, 'page');
    assertContains(e(t('install.req.title')), installGet($installer)->body, 'still on the requirements step');
});

test('the right install token opens the database step', function () {
    $installer = installer();
    installGet($installer);

    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');
    assertContains(e(t('install.db.title')), installGet($installer)->body, 'database step');
});

test('a failed requirement blocks the installer with no way around it', function () {
    $installer = installer(false);
    $page = installGet($installer);

    assertContains(e(t('install.req.blocked')), $page->body, 'requirements page');
    assertTrue(!str_contains($page->body, 'name="token"'), 'no token form while blocked');
    assertEquals(422, installPost($installer, ['token' => 'anything'])->status, 'POST while blocked');
});

testBothDrivers('a full install creates the admin, primary locale, settings, .env and lock', function (string $driver) {
    $db = freshDatabase($driver);
    $installer = installer();
    installGet($installer);
    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');

    $database = $driver === 'sqlite'
        ? ['driver' => 'sqlite', 'path' => tmpPath('test.sqlite')]
        : array_map('strval', ['driver' => 'mysql'] + array_diff_key(mysqlTestConfig(), ['driver' => 0]));
    assertAdvanced(installPost($installer, $database), 'database step');
    $password = 'correct horse battery staple';
    assertAdvanced(installPost($installer, ['email' => 'Owner@Example.com', 'password' => $password, 'password_confirm' => $password]), 'admin step');
    $done = installPost($installer, ['name' => 'Test Site', 'locale' => 'hr', 'timezone' => 'Europe/Zagreb']);
    assertContains(e(t('install.done.title')), $done->body, 'done page');

    $dir = tmpPath('install');
    assertTrue(is_file($dir . '/storage/install.lock'), 'install.lock written');
    assertTrue(!is_file($dir . '/storage/install-token.txt'), 'install token removed');
    assertTrue(!is_file($dir . '/install.php'), 'install.php deleted');
    $env = Dotenv::parse((string) file_get_contents($dir . '/.env'));
    assertEquals($driver, $env['DB_DRIVER'] ?? null, '.env DB_DRIVER');
    assertEquals(64, strlen((string) ($env['APP_KEY'] ?? '')), '.env APP_KEY length');

    $admin = $db->one('SELECT email, password_hash FROM admin');
    assertEquals('owner@example.com', $admin['email'] ?? null, 'admin email, lower-cased');
    assertTrue(password_verify($password, (string) ($admin['password_hash'] ?? '')), 'admin password verifies');
    $locales = array_map(
        static fn (array $row): array => [(string) $row['code'], (string) $row['label'], (int) $row['is_primary'], (int) $row['enabled']],
        $db->all('SELECT code, label, is_primary, enabled FROM locales'),
    );
    assertEquals([['hr', 'Hrvatski', 1, 1]], $locales, 'locales: only the primary, enabled');
    $siteName = $db->one('SELECT value_json FROM settings WHERE `key` = ?', ['site_name']);
    assertEquals('"Test Site"', $siteName['value_json'] ?? null, 'settings.site_name');

    assertEquals(403, installGet($installer)->status, 'the installer refuses to run again');
});

test('.env values survive quoting, including quotes, backslashes and $', function () {
    $values = ['A' => "p'a\"s\$w\\o#rd x", 'B' => '${HOME}', 'C' => '  spaced  ', 'D' => '$1$abc', 'E' => ''];

    assertEquals($values, Dotenv::parse(Installer::envFile($values)), 'parsed .env');
});

test('an unreachable MySQL server is named as such', function () {
    $mysql = ['host' => '127.0.0.1', 'port' => 1, 'database' => 'boxlet', 'username' => 'boxlet', 'password' => 'x'];

    assertThrows(fn () => DatabaseSetup::mysql($mysql), t('install.db.mysql_2002', ['host' => '127.0.0.1', 'port' => 1]));
});

test('wrong MySQL credentials are named as such', function () {
    $config = mysqlTestConfig();
    $mysql = ['host' => $config['host'], 'port' => $config['port'], 'database' => $config['database'], 'username' => $config['username'], 'password' => 'definitely-wrong'];

    assertThrows(fn () => DatabaseSetup::mysql($mysql), t('install.db.mysql_1045', ['user' => $config['username']]));
});

test('installing over an existing Boxlet database is refused', function () {
    migratedDatabase('sqlite');

    assertThrows(fn () => DatabaseSetup::sqlite(dirname(__DIR__), tmpPath('test.sqlite')), t('install.db.not_empty', ['table' => 'migrations']));
});

test('a SQLite file inside public/ is refused', function () {
    assertThrows(fn () => DatabaseSetup::sqlite(dirname(__DIR__), 'public/cache/site.sqlite'), t('install.db.sqlite_public'));
});

test('the language list has every ISO 639-1 code with a native name', function () {
    $languages = require dirname(__DIR__) . '/app/Modules/I18n/languages.php';

    assertEquals(183, count($languages), 'number of languages');
    foreach (['en' => 'English', 'hr' => 'Hrvatski', 'de' => 'Deutsch', 'ja' => '日本語'] as $code => $name) {
        assertEquals($name, $languages[$code] ?? null, "native name for {$code}");
    }
    foreach (array_keys($languages) as $code) {
        assertTrue((bool) preg_match('~^[a-z]{2}$~', (string) $code), "{$code} is a two-letter code");
    }
});

test('the requirements report max_input_vars and require dom', function () {
    $checks = Requirements::check(dirname(__DIR__), tmpPath(''), tmpPath('.env'), static fn (): bool => true);
    $labels = array_column($checks, 'label');

    assertTrue(in_array(t('install.req.extension', ['name' => 'dom']), $labels, true), 'dom is not a required extension');
    $inputVars = null;
    foreach ($checks as $check) {
        if ($check['id'] === 'input_vars') {
            $inputVars = $check;
        }
    }
    $inputVars ??= fail('no max_input_vars check');
    assertEquals(false, $inputVars['required'], 'max_input_vars blocks installation');
    assertContains((string) ini_get('max_input_vars'), $inputVars['label'], 'label');
});

test('Db refuses drivers other than mysql and sqlite', function () {
    assertThrows(fn () => new Db('pgsql', 'pgsql:host=x'), 'Unsupported database driver');
});
