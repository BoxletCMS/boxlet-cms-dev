<?php

/**
 * Minimal test runner: php tests/run.php
 *
 * Loads every tests/*_test.php, runs each registered test, prints one line per test
 * and exits non-zero if any failed. Any PHP notice, warning or deprecation fails the
 * test that raised it. A skipped test (MySQL not configured) is reported, not failed,
 * unless TEST_REQUIRE_MYSQL=1, as CI sets.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run composer install first.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';
require __DIR__ . '/support.php';
require __DIR__ . '/fixtures.php';

$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
foreach ($files as $file) {
    TestSuite::$group = basename($file, '_test.php');
    require $file;
}

$requireMysql = getenv('TEST_REQUIRE_MYSQL') === '1';
$failed = 0;
$skipped = 0;
foreach (TestSuite::$tests as [$name, $body]) {
    $_SESSION = [];
    TestSite::$env = [];
    try {
        $body();
        echo "  PASS  {$name}\n";
    } catch (TestSkipped $e) {
        if ($requireMysql) {
            $failed++;
            echo "  FAIL  {$name}\n        skipped, but TEST_REQUIRE_MYSQL=1: {$e->getMessage()}\n";
            continue;
        }
        $skipped++;
        echo "  SKIP  {$name}\n        {$e->getMessage()}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n";
        echo '        ' . failureMessage($e) . "\n";
    }
}

cleanupTestState();

$total = count(TestSuite::$tests);
$passed = $total - $failed - $skipped;
$summary = $failed === 0 ? "OK: {$passed} passed" : "FAILED: {$failed} failed, {$passed} passed";
echo "\n{$summary}" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ({$total} tests)\n";
exit($failed === 0 ? 0 : 1);
