<?php

use App\Core\Db;
use App\Core\Request;
use App\Modules\Install\DatabaseSetup;
use App\Modules\Install\PrivateCheck;
use App\Modules\Install\Requirements;

// Boxlet in the web root itself, cPanel's public_html (PLAN.md D-138): the address a
// request came in on decides the base path, and the installer refuses a folder whose files
// beside public/ can be downloaded. The .htaccess files themselves are Apache's to run, and
// were checked on a real cPanel host; see D-138.

test('the base path is the script\'s folder when the address starts with it', function () {
    assertEquals('', Request::basePath('/index.php', '/about'), 'domain at public/');
    assertEquals('/boxlet', Request::basePath('/boxlet/index.php', '/boxlet/about'), 'in a folder');
    assertEquals('/boxlet', Request::basePath('/boxlet/index.php', '/boxlet'), 'the folder itself');
    assertEquals('', Request::basePath('index.php', '/about'), 'no folder at all');
});

test('in the web root itself the base path leaves out the public/ the address never had', function () {
    assertEquals('', Request::basePath('/public/index.php', '/about'), 'a page');
    assertEquals('', Request::basePath('/public/index.php', '/'), 'the home page');
    assertEquals('', Request::basePath('/public/install.php', '/install.php'), 'the installer');
    assertEquals('', Request::basePath('/public/index.php', '/public-speaking'), 'an address that only begins with the word');
    assertEquals('/boxlet', Request::basePath('/boxlet/public/index.php', '/boxlet/about'), 'in a folder of the web root');
    // Reached as /public/…, which the .htaccess sends on, the base is still the one used.
    assertEquals('/public', Request::basePath('/public/index.php', '/public/about'), 'asked for with public/ in it');
});

test('fromGlobals takes the path and the base from the same rule', function () {
    $saved = $_SERVER;
    try {
        $_SERVER['SCRIPT_NAME'] = '/public/index.php';
        $_SERVER['REQUEST_URI'] = '/services/web-design?x=1';
        $request = Request::fromGlobals();
        assertEquals('', $request->basePath, 'base');
        assertEquals('/services/web-design', $request->path, 'path');
    } finally {
        $_SERVER = $saved;
    }
});

test('the installer requires that nothing beside public/ can be downloaded', function () {
    $checks = Requirements::check(dirname(__DIR__), tmpPath(''), tmpPath('.env'), static fn (): bool => true, static fn (): bool => false);
    $private = null;
    foreach ($checks as $check) {
        if ($check['id'] === 'private') {
            $private = $check;
        }
    }
    $private ??= fail('no private check');
    assertEquals(true, $private['required'], 'required');
    assertEquals(false, $private['ok'], 'ok');
    assertTrue(Requirements::blocked($checks), 'a folder that can be downloaded does not block installing');
});

/**
 * A PHP server over $docroot for as long as $body runs. PHP's own server, with no router,
 * serves a file that is there and answers 404 for one that is not, which is all a web
 * server has to do for PrivateCheck.
 */
function withServer(string $docroot, Closure $body): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $socket !== false || fail('no free port');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    is_resource($process) || fail('could not start the server');
    try {
        // Refused until the server is up: expected, not an error to report.
        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 50 && fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
        } finally {
            restore_error_handler();
        }
        $body("http://127.0.0.1:{$port}");
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

test('PrivateCheck finds a folder beside public/ that is served, and says so', function () {
    $site = tmpPath('web-root');
    removeTree($site);
    mkdir($site . '/public', 0700, true);

    // The whole folder served, as a host that ignores the .htaccess would.
    withServer($site, function (string $origin) use ($site): void {
        assertEquals(false, PrivateCheck::hidden($site, $origin, '', 2.0), 'served at the base');
        // Reached as /public/install.php: the folder is found at the address above it.
        assertEquals(false, PrivateCheck::hidden($site, $origin . '/public', '/public', 2.0), 'served above /public');
    });
    // Only public/ served, as with the domain pointed at it.
    withServer($site . '/public', function (string $origin) use ($site): void {
        assertEquals(true, PrivateCheck::hidden($site, $origin, '', 2.0), 'only public/ served');
    });

    assertEquals([], glob($site . '/boxlet-private-check-*') ?: [], 'the probe file was left behind');
    removeTree($site);
});

function charsetOf(Db $db): string
{
    return (string) ($db->one('SELECT @@character_set_database AS c')['c'] ?? '');
}

/**
 * Runs $body with the MySQL test database's default character set at $charset, and puts it
 * back to utf8mb4 afterwards whatever happens: every other test makes its tables in it.
 */
function withCharset(string $charset, Closure $body): void
{
    $config = mysqlTestConfig();
    $db = freshDatabase('mysql');
    $name = '`' . str_replace('`', '``', $config['database']) . '`';
    try {
        $db->query("ALTER DATABASE {$name} CHARACTER SET {$charset}");
        $body($db);
    } finally {
        $db->query('DROP TABLE IF EXISTS not_boxlets');
        $db->query("ALTER DATABASE {$name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}

test('an empty database in latin1, as cPanel makes them, is switched to utf8mb4 by the installer', function () {
    withCharset('latin1', function (Db $db): void {
        assertEquals('latin1', charsetOf($db), 'the test starts in latin1');
        assertEquals('utf8mb4', charsetOf(DatabaseSetup::mysql(mysqlTestConfig())), 'after the installer');
    });
});

test('a latin1 database that holds tables is reported, not changed', function () {
    withCharset('latin1', function (Db $db): void {
        $db->query('CREATE TABLE not_boxlets (id INT)');
        assertThrows(static fn () => DatabaseSetup::mysql(mysqlTestConfig()), 'utf8mb4');
        assertEquals('latin1', charsetOf($db), 'left as it was');
    });
});
