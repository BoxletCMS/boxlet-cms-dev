<?php

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

final class AssertionFailed extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
{
    /**
     * What the machine lacked: 'mysql', 'images', and so on. A CI leg that is supposed to
     * have something sets TEST_REQUIRE_<CAPABILITY>=1, so a test skipping there is a
     * failure — while a leg deliberately running without it stays green.
     */
    public string $capability = 'mysql';
}

final class TestSuite
{
    /** @var list<array{string, Closure}> */
    public static array $tests = [];

    /** Name of the *_test.php file being loaded, used as a prefix. */
    public static string $group = '';
}

function test(string $name, Closure $body): void
{
    TestSuite::$tests[] = [TestSuite::$group . ': ' . $name, $body];
}

/**
 * Registers the test once per database driver. The body receives 'sqlite' or 'mysql';
 * MySQL runs skip when no test database is configured.
 */
function testBothDrivers(string $name, Closure $body): void
{
    foreach (['sqlite', 'mysql'] as $driver) {
        test("{$name} [{$driver}]", static fn () => $body($driver));
    }
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

/**
 * Reports this test as skipped, naming what was missing.
 *
 * $capability decides which TEST_REQUIRE_* flag turns this skip into a failure. It
 * defaults to mysql because that was the only kind of skip for a long time — an assumption
 * that was already wrong: the post_max_size skip in media_admin_test was being failed under
 * TEST_REQUIRE_MYSQL, with a message blaming MySQL for something it had no part in.
 */
function skip(string $reason, string $capability = 'mysql'): never
{
    $skipped = new TestSkipped($reason);
    $skipped->capability = $capability;

    throw $skipped;
}

function assertEquals(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        fail(sprintf('%s: expected %s, got %s', $what, export($expected), export($actual)));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function assertContains(string $needle, string $haystack, string $what = 'output'): void
{
    if (!str_contains($haystack, $needle)) {
        $excerpt = preg_replace('~\s+~', ' ', mb_substr($haystack, 0, 300));
        fail(sprintf('%s does not contain %s. Start of %s: %s', $what, export($needle), $what, export($excerpt)));
    }
}

/**
 * Fails unless $action throws an exception whose message contains $messagePart.
 */
function assertThrows(Closure $action, string $messagePart): void
{
    try {
        $action();
    } catch (AssertionFailed $e) {
        throw $e;
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $messagePart)) {
            fail(sprintf('expected an exception containing %s, got %s', export($messagePart), export($e->getMessage())));
        }

        return;
    }
    fail(sprintf('expected an exception containing %s, nothing was thrown', export($messagePart)));
}

function export(mixed $value): string
{
    return str_replace("\n", ' ', var_export($value, true));
}

/**
 * Environment of the site the current test installed with installedSite(). The runner
 * clears it before every test.
 */
final class TestSite
{
    /** @var array<string, string> */
    public static array $env = [];
}

/**
 * Dispatches a request through app/bootstrap.php and the Router, as public/index.php
 * does, without a web server. The session is plain $_SESSION, which persists across
 * dispatches within a test.
 *
 * @param array<string, mixed> $body POST fields
 */
function dispatch(
    string $path,
    ?Closure $configureRouter = null,
    string $method = 'GET',
    array $body = [],
    string $ip = '203.0.113.10',
    ?Closure $configureContainer = null,
): Response {
    $saved = [$_SERVER, $_GET, $_POST, $_ENV];
    try {
        $_ENV = TestSite::$env + $_ENV;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['SERVER_NAME'] = 'example.test';
        $_SERVER['SERVER_PORT'] = '80';
        unset($_SERVER['HTTPS']);
        // As PHP does for a real request: the query string of the path becomes $_GET.
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_POST = $body;

        $container = require dirname(__DIR__) . '/app/bootstrap.php';
        $container->set('session', static fn () => new Session());
        // Replace a service before anything resolves it. The configurator above takes the
        // Router, which is built from the container and so cannot reach it — a test that
        // needs the request to see a different service (a pending update, say) has
        // nowhere else to stand.
        if ($configureContainer !== null) {
            $configureContainer($container);
        }
        /** @var Router $router */
        $router = $container->get('router');
        if ($configureRouter !== null) {
            $configureRouter($router);
        }

        return $router->dispatch($container->get('request'));
    } finally {
        [$_SERVER, $_GET, $_POST, $_ENV] = $saved;
    }
}

/**
 * One line naming what failed and where, pointing at the test file rather than at
 * this helper.
 */
function failureMessage(Throwable $e): string
{
    $location = '';
    foreach ($e->getTrace() as $frame) {
        if (isset($frame['file']) && str_ends_with($frame['file'], '_test.php')) {
            $location = sprintf(' (%s:%d)', basename($frame['file']), $frame['line'] ?? 0);
            break;
        }
    }
    $prefix = $e instanceof AssertionFailed ? '' : get_class($e) . ': ';

    return $prefix . $e->getMessage() . $location;
}
