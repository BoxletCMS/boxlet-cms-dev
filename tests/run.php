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

/**
 * A failing test, repeated as a GitHub workflow annotation.
 *
 * Reading a job's log needs admin rights on the repository, so a failure that exists only
 * in the log is one nobody else can diagnose — twelve red runs went unexamined partly for
 * that reason. ::error:: lines become check-run annotations, which the PUBLIC API exposes,
 * so the name of the failing test can be read by anyone who can see the repository.
 *
 * Silent outside Actions: locally the ordinary output is already right there.
 */
$annotate = static function (string $name, string $detail): void {
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        return;
    }
    // Annotations are one line, and % CR LF carry meaning in a workflow command.
    $clean = static fn (string $text): string => str_replace(
        ['%', "\r", "\n"],
        ['%25', '%0D', '%0A'],
        $text,
    );
    echo '::error title=' . $clean($name) . '::' . $clean($detail) . "\n";
};
$failed = 0;
$skipped = 0;
foreach (TestSuite::$tests as [$name, $body]) {
    $_SESSION = [];
    // A page-path resolver holds the last site's pages (D-129); a test starts with none.
    App\Support\Url::usePaths(null);
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
            $annotate($name, "skipped, but {$flag}=1: " . $e->getMessage());
            continue;
        }
        $skipped++;
        echo "  SKIP  {$name}\n        {$e->getMessage()}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n";
        echo '        ' . failureMessage($e) . "\n";
        $annotate($name, failureMessage($e));
    }
}

cleanupTestState();

$total = count(TestSuite::$tests);
$passed = $total - $failed - $skipped;
$summary = $failed === 0 ? "OK: {$passed} passed" : "FAILED: {$failed} failed, {$passed} passed";
echo "\n{$summary}" . ($skipped > 0 ? ", {$skipped} skipped" : '') . " ({$total} tests)\n";
exit($failed === 0 ? 0 : 1);
