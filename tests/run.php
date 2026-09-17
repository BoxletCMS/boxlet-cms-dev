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

/**
 * Whether a skip for this capability is a failure here. A CI leg that installs MySQL, or
 * GD and Imagick, sets the matching flag: a test that skips on such a leg is a test that
 * silently stopped covering anything.
 */
$required = static fn (string $capability): bool
    => getenv('TEST_REQUIRE_' . strtoupper($capability)) === '1';
$failed = 0;
$skipped = 0;
foreach (TestSuite::$tests as [$name, $body]) {
    $_SESSION = [];
    TestSite::$env = [];
    // The storage directory is one fixed path shared by every test, so state written into
    // it outlives the test that wrote it. Each of these changes what a LATER test sees:
    //
    //   maintenance.flag   closes the site — eight render tests became 503s this way
    //   migrations.state   makes pending() answer from the file without querying, so a
    //                      test can be told "nothing pending" about a database it has
    //                      never looked at, which passes rather than fails
    //   update.lock        makes any later run() refuse as already running
    //
    // Cleared here rather than trusting every test to tidy up, because the failure from
    // forgetting lands on a different test and reads as a bug in that one.
    foreach (['maintenance.flag', 'migrations.state', 'update.lock'] as $leftover) {
        $file = tmpPath('storage') . '/' . $leftover;
        if (is_file($file)) {
            unlink($file);
        }
    }
    try {
        $body();
        echo "  PASS  {$name}\n";
    } catch (TestSkipped $e) {
        if ($required($e->capability)) {
            $failed++;
            $flag = 'TEST_REQUIRE_' . strtoupper($e->capability);
            echo "  FAIL  {$name}\n        skipped, but {$flag}=1: {$e->getMessage()}\n";
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
