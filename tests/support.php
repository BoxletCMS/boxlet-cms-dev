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
 * Dispatches a GET request through app/bootstrap.php and the Router, as
 * public/index.php does, without a web server. $configureRouter may add routes
 * before dispatch.
 */
function dispatch(string $path, ?Closure $configureRouter = null): Response
{
    $saved = [$_SERVER, $_GET, $_POST];
    try {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        $_POST = [];

        $container = require dirname(__DIR__) . '/app/bootstrap.php';
        /** @var Router $router */
        $router = $container->get('router');
        if ($configureRouter !== null) {
            $configureRouter($router);
        }

        return $router->dispatch($container->get('request'));
    } finally {
        [$_SERVER, $_GET, $_POST] = $saved;
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
