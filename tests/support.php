<?php

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

final class AssertionFailed extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
{
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

function skip(string $reason): never
{
    throw new TestSkipped($reason);
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
 * @param array<string, string> $body POST fields
 */
function dispatch(
    string $path,
    ?Closure $configureRouter = null,
    string $method = 'GET',
    array $body = [],
    string $ip = '203.0.113.10',
): Response {
    $saved = [$_SERVER, $_GET, $_POST, $_ENV];
    try {
        $_ENV = TestSite::$env + $_ENV;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REMOTE_ADDR'] = $ip;
        unset($_SERVER['HTTPS']);
        $_GET = [];
        $_POST = $body;

        $container = require dirname(__DIR__) . '/app/bootstrap.php';
        $container->set('session', static fn () => new Session());
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
