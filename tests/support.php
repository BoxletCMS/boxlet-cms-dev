<?php

use App\Core\Response;
use App\Core\Router;

final class AssertionFailed extends RuntimeException
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
 * Registers the test twice: once with pretty URLs, once with the index.php?route=
 * fallback. The body receives $pretty.
 */
function testBothModes(string $name, Closure $body): void
{
    foreach (['pretty' => true, 'fallback' => false] as $mode => $pretty) {
        test("{$name} [{$mode}]", static fn () => $body($pretty));
    }
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
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

function export(mixed $value): string
{
    return str_replace("\n", ' ', var_export($value, true));
}

/**
 * The URL a page link or redirect should have in the given mode. Written out
 * independently of App\Support\Url so tests do not trust the code they check.
 */
function expectedUrl(string $path, bool $pretty): string
{
    return $pretty ? $path : '/index.php?route=' . $path;
}

/**
 * Dispatches a GET request through app/bootstrap.php and the Router, as
 * public/index.php does, without a web server. $pretty selects both how the request
 * arrives (/en/hello vs index.php?route=/en/hello) and how URLs are generated.
 * $configureRouter may add routes before dispatch.
 */
function dispatch(string $path, bool $pretty, ?Closure $configureRouter = null): Response
{
    $saved = [$_SERVER, $_GET, $_POST, $_ENV];
    try {
        $_ENV['APP_PRETTY_URLS'] = $pretty ? 'true' : 'false';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $pretty ? $path : '/index.php?route=' . rawurlencode($path);
        $_GET = $pretty ? [] : ['route' => $path];
        $_POST = [];

        $container = require dirname(__DIR__) . '/app/bootstrap.php';
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
