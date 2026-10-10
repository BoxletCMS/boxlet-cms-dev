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

function installer(bool $rewriteWorks = true, bool $privateHidden = true, ?Closure $demoStore = null): InstallController
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
        static fn (): bool => $privateHidden,
        $dir . '/cache',
        $demoStore,
    );
}

/**
 * A stand-in for storing a demo picture (D-214): a row in the installed site's library, every
 * size "made", in the time asked. Making fifty pictures' sizes is the media tests' business.
 *
 * @param list<string> $taken the names it was asked for, in order
 */
function demoStandIn(array &$taken): Closure
{
    return static function (string $file, string $name, float $seconds) use (&$taken): array {
        $taken[] = $name;
        $db = new App\Core\Db('sqlite', 'sqlite:' . tmpPath('test.sqlite'));

        return ['id' => storedPicture($db, $name, ['card' => ['width' => 400, 'height' => 300, 'formats' => ['webp']]]), 'complete' => true, 'made' => 1];
    };
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
    // A page kept by a site that stood here before: public/cache outlives a reinstall.
    $dir = tmpPath('install');
    mkdir($dir . '/cache/pages', 0777, true);
    file_put_contents($dir . '/cache/pages/' . sha1('/') . '.html', 'the old site');
    $done = installPost($installer, ['name' => 'Test Site', 'locale' => 'hr', 'timezone' => 'Europe/Zagreb']);
    assertContains(e(t('install.done.title')), $done->body, 'done page');
    assertEquals([], glob($dir . '/cache/pages/*.html'), 'the old site\'s kept pages');

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
    // NOTHING IS STORED SINCE D-164: design_tokens holds the owner's own values, and a fresh
    // install has none — the site is the default character until somebody changes it. The
    // stylesheet below is what proves the design was published.
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'design decisions stored');
    $stylesheet = json_decode((string) ($db->one('SELECT value_json FROM settings WHERE `key` = ?', ['tokens_css'])['value_json'] ?? ''), true);
    assertTrue(is_string($stylesheet) && is_file($dir . '/cache/' . $stylesheet), 'the default design stylesheet was not compiled');

    assertEquals(403, installGet($installer)->status, 'the installer refuses to run again');
});

test('the site step shows that it is at work once sent, and its parts are there (D-212)', function () {
    freshDatabase('sqlite');
    $installer = installer();
    installGet($installer);
    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');
    assertAdvanced(installPost($installer, ['driver' => 'sqlite', 'path' => tmpPath('test.sqlite')]), 'database step');
    $password = 'correct horse battery staple';
    assertAdvanced(installPost($installer, ['email' => 'owner@example.com', 'password' => $password, 'password_confirm' => $password]), 'admin step');
    $page = installGet($installer)->body;

    assertContains(e(t('install.site.title')), $page, 'the site step');
    assertContains('data-install-busy', $page, 'the form the script watches');
    assertContains('data-busy-label="' . e(t('install.site.working')) . '"', $page, 'the button\'s words while at work');
    // Hidden at rest: a status, not a control, and nothing to say before the form is sent.
    assertTrue(preg_match('~<div class="install-busy" role="status" hidden>.*?' . preg_quote(e(t('install.site.busy')), '~') . '~s', $page) === 1, 'the hidden status with its sentence');
    foreach (['assets/install.js', 'assets/admin-install.css'] as $asset) {
        assertContains($asset, $page, $asset . ' linked');
        assertTrue(is_file(dirname(__DIR__) . '/public/' . $asset), $asset . ' exists');
    }
});

// D-214: with the demo, the install comes in two halves with its pictures between, a few to a
// request, so no request runs as long as fifty pictures' sizes (a Cloudflare 524 at 100 s).
test('with the demo, the install stops for its pictures and finishes when the last is in', function () {
    freshDatabase('sqlite');
    $taken = [];
    $installer = installer(true, true, demoStandIn($taken));
    installGet($installer);
    assertAdvanced(installPost($installer, ['token' => installToken()]), 'token step');
    assertAdvanced(installPost($installer, ['driver' => 'sqlite', 'path' => tmpPath('test.sqlite')]), 'database step');
    $password = 'correct horse battery staple';
    assertAdvanced(installPost($installer, ['email' => 'owner@example.com', 'password' => $password, 'password_confirm' => $password]), 'admin step');
    assertAdvanced(installPost($installer, ['name' => 'Demo', 'locale' => 'en', 'timezone' => 'UTC', 'demo' => '1']), 'site step, with the demo');

    // Between the halves: the database made, nothing finished.
    $dir = tmpPath('install');
    $db = new App\Core\Db('sqlite', 'sqlite:' . tmpPath('test.sqlite'));
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM admin')['n'] ?? -1), 'the admin, made');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'no pages yet');
    assertTrue(!is_file($dir . '/storage/install.lock') && !is_file($dir . '/.env') && is_file($dir . '/install.php'), 'no lock, no .env, install.php still there');
    $total = count(App\Modules\Install\InstallDemo::pictures('en'));
    $page = installGet($installer)->body;
    assertContains(e(t('install.demo.title')), $page, 'the pictures\' page');
    assertContains(e(t('install.demo.count', ['done' => 0, 'total' => $total, 'sizes' => 0])), $page, 'none of them in');
    assertContains('data-install-continue', $page, 'and the form that goes on by itself');

    $done = installPost($installer, []);
    assertContains(e(t('install.done.title')), $done->body, 'the stand-in takes them all in one request, and the install finishes');
    assertEquals($total, count($taken), 'every picture taken, once');
    assertTrue(is_file($dir . '/storage/install.lock') && is_file($dir . '/.env') && !is_file($dir . '/install.php'), 'the lock and .env written, install.php gone');
    $hero = $db->one("SELECT b.content_json FROM page_blocks b JOIN pages p ON p.id = b.page_id WHERE p.slug = '' AND p.locale = 'en' AND b.block_type = 'hero'");
    $content = json_decode((string) ($hero['content_json'] ?? ''), true);
    $id = (int) ($db->one("SELECT id FROM media WHERE filename = 'abandoned-workshop-hall.webp'")['id'] ?? 0);
    assertTrue($id > 0 && ($content['image'] ?? null) === $id, 'the pictures taken are the ones the pages show');
});

// When the package does not come, the page says so and which try it is (D-215): the owner saw
// a bar standing at 0 MB and not a word.
test('the demo\'s page says why its package did not come, and which try it is', function () {
    $state = ['error' => 'HTTP 404', 'failures' => 2] + App\Modules\Install\InstallDemo::START;
    $shown = App\Modules\Install\InstallDemo::progress($state, 'en', tmpPath('none'), null);
    assertContains(t('install.demo.fetch_failed', ['error' => 'HTTP 404', 'try' => 2, 'tries' => 5]), $shown['text'], 'the error and the try');
    assertEquals(0, $shown['value'], 'nothing in yet');
});

// One request's worth, and the next goes on from there (D-214). A picture is never started with
// too little time left (it was stored with no size and counted as tried, which on the owner's
// host left most pictures without any), and only a picture whose sizes stop coming is let go.
test('the demo\'s pictures are taken while there is time, kept on while their sizes come, and let go only when they stop', function () {
    $calls = [];
    $calls_b = 0;
    $store = static function (string $file, string $name, float $seconds) use (&$calls, &$calls_b): array {
        $calls[] = $name;
        usleep(100000);
        if ($name === 'b.webp') {
            $calls_b++;

            return ['id' => 2, 'complete' => $calls_b >= 5, 'made' => 2];
        }

        return $name === 'a.webp' ? ['id' => 1, 'complete' => true, 'made' => 6] : ['id' => 3, 'complete' => false, 'made' => 0];
    };
    $files = ['a.webp', 'b.webp', 'c.webp'];
    $fresh = ['done' => [], 'stalls' => [], 'sizes' => 0];

    $state = App\Modules\Install\InstallDemo::batch($files, $fresh, $store, 3.9);
    assertEquals([], $calls, 'less than four seconds: no picture started');

    // 4.05 s: one picture, then too little left for the next.
    $state = App\Modules\Install\InstallDemo::batch($files, $state, $store, 4.05);
    assertEquals(['a.webp'], $calls, 'one picture in the time');
    assertEquals(['a.webp' => 1], $state['done'], 'and it is in, every size made');
    assertEquals(6, $state['sizes'], 'its sizes counted');
    foreach (range(1, 4) as $n) {
        $state = App\Modules\Install\InstallDemo::batch($files, $state, $store, 4.05);
    }
    assertEquals(4, $calls_b, 'b four times');
    assertTrue(!isset($state['done']['b.webp']), 'b, its sizes still coming, is not let go after three');
    $state = App\Modules\Install\InstallDemo::batch($files, $state, $store, 4.05);
    assertEquals(2, $state['done']['b.webp'] ?? null, 'b in, on the fifth request');
    assertEquals(16, $state['sizes'], 'every size counted');

    foreach (range(1, 3) as $n) {
        $state = App\Modules\Install\InstallDemo::batch($files, $state, $store, 4.05);
    }
    assertEquals(3, $state['done']['c.webp'] ?? null, 'c, three requests with no size made, let go: Finish in Media takes it on');
    assertEquals(['a.webp', 'b.webp', 'b.webp', 'b.webp', 'b.webp', 'b.webp', 'c.webp', 'c.webp', 'c.webp'], $calls, 'nothing taken again once in');

    // A picture the server refuses: the demo goes on, that field empty.
    $refusing = static function (string $file, string $name, float $seconds): array {
        throw new RuntimeException('no image extension');
    };
    $state = App\Modules\Install\InstallDemo::batch(['x.webp'], $fresh, $refusing, 10.0);
    assertEquals(['x.webp' => 0], $state['done'], 'counted as done, with no picture');
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
    $checks = Requirements::check(dirname(__DIR__), tmpPath(''), tmpPath('.env'), static fn (): bool => true, static fn (): bool => true);
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

// D-215: the demo's package, fetched a piece to a request, checked against the hash written in
// the code, unpacked; and a picture from it put in place with its sizes copied, not made.
test('the demo\'s package is fetched, checked and unpacked, and a picture placed from it with its sizes', function () {
    $dir = tmpPath('package');
    removeTree($dir);
    mkdir($dir . '/storage', 0700, true);
    mkdir($dir . '/public', 0700, true);
    mkdir($dir . '/build/prepared/m/card', 0700, true);
    mkdir($dir . '/build/texture', 0700, true);
    // A JPEG of the media tests' own: GD makes it, with no EXIF to warn about.
    imageFixture($dir . '/build/texture/kraft-cardboard.jpg');
    file_put_contents($dir . '/build/prepared/m/card/7-kraft-cardboard.webp', 'a card, made where the package was built');
    $variants = '{"card":{"width":600,"height":600,"formats":["webp"]}}';
    file_put_contents($dir . '/build/prepared/manifest.json', json_encode(['version' => 'test', 'pictures' => ['texture/kraft-cardboard.jpg' => ['id' => 7, 'filename' => 'kraft-cardboard', 'variants_json' => $variants]]]));
    $zipFile = $dir . '/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    foreach (['texture/kraft-cardboard.jpg', 'prepared/m/card/7-kraft-cardboard.webp', 'prepared/manifest.json'] as $entry) {
        $zip->addFile($dir . '/build/' . $entry, $entry);
    }
    $zip->close();
    $package = ['version' => 'test', 'url' => 'https://example.test/demo.zip', 'bytes' => (int) filesize($zipFile), 'sha256' => (string) hash_file('sha256', $zipFile)];
    $asked = [];
    $piece = static function (string $url, int $from, int $to, string $etag, string $target) use ($zipFile, &$asked): array {
        $asked[] = "{$from}-{$to}";
        file_put_contents($target, substr((string) file_get_contents($zipFile), $from, $to - $from + 1));

        return ['bytes' => $to - $from + 1, 'total' => (int) filesize($zipFile), 'etag' => '', 'whole' => false, 'error' => ''];
    };

    // A package that is not the one released is refused and fetched again from the start.
    $wrong = ['sha256' => str_repeat('0', 64)] + $package;
    $answer = App\Modules\Install\InstallDemoPackage::fetch($dir, $dir . '/storage', 10.0, $piece, $wrong);
    assertTrue(!$answer['ready'] && $answer['bytes'] === 0 && $answer['error'] !== '', 'a wrong hash refused: ' . json_encode($answer));
    assertTrue(!is_file($dir . '/storage/demo-package.zip'), 'and what came is thrown away');

    $answer = App\Modules\Install\InstallDemoPackage::fetch($dir, $dir . '/storage', 10.0, $piece, $package);
    assertTrue($answer['ready'], 'fetched, checked and unpacked: ' . json_encode($answer));
    assertEquals(['0-' . ($package['bytes'] - 1)], array_slice($asked, -1), 'asked for as a range');
    $folder = App\Modules\Install\InstallDemoPackage::folder($dir . '/storage');
    assertTrue(is_file($folder . '/prepared/manifest.json'), 'unpacked under storage/demo');

    $db = installedSite(['en' => 'English']);
    $made = static fn (): array => throw new RuntimeException('the sizes were made, not copied');
    $place = App\Modules\Install\InstallDemoPackage::placer($db, $dir . '/storage', $dir . '/public', $folder, Closure::fromCallable($made));
    $placed = $place($folder . '/texture/kraft-cardboard.jpg', 'kraft-cardboard.jpg', 10.0);
    $row = $db->one('SELECT filename, status, variants_json FROM media WHERE id = ?', [$placed['id']]);
    assertTrue($placed['complete'] && $placed['made'] === 1, 'placed, one size copied: ' . json_encode($placed));
    assertEquals('complete', $row['status'] ?? null, 'the picture is complete');
    assertEquals($variants, $row['variants_json'] ?? null, 'its sizes as the package made them');
    assertEquals('a card, made where the package was built', (string) @file_get_contents($dir . '/public/m/card/' . $placed['id'] . '-' . ($row['filename'] ?? '') . '.webp'), 'the size copied under the new id');

    App\Modules\Install\InstallDemoPackage::clean($dir . '/storage');
    assertTrue(!is_dir($folder) && !is_file($dir . '/storage/demo-package.zip'), 'the package cleaned away');
});
