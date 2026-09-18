<?php

use App\Core\Migrator;

// Applying pending migrations from the command line (PLAN.md D-033).
//
// The script is run as a real process, as a developer runs it, with the test site's
// database and storage passed in the environment. Real environment variables win over
// .env because the script loads it immutably, so the checkout's own .env cannot point
// this at a real site.

/**
 * An installed site whose database lacks the newest migration in migrations/, so the
 * script has exactly one file to apply whatever the project's migration set holds.
 *
 * @return string the file left pending
 */
function siteMissingNewestMigration(string $driver): string
{
    installedSite(['en' => 'English'], $driver);
    $db = freshDatabase($driver);

    $files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
    sort($files);
    $newest = basename((string) array_pop($files));

    $dir = tmpPath('migrate-cli');
    removeTree($dir);
    mkdir($dir, 0700, true);
    foreach ($files as $file) {
        copy($file, $dir . '/' . basename($file));
    }
    (new Migrator($db, $dir))->migrate();
    $db->query("INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES ('en', 'English', 1, 0, 1)");

    // The marker says whether the directory was already seen; a stale one from another
    // test would answer "up to date" without asking the database at all.
    $marker = TestSite::$env['STORAGE_PATH'] . '/migrations.state';
    if (is_file($marker)) {
        unlink($marker);
    }

    return $newest;
}

/**
 * @return array{0: int, 1: string} exit code and everything printed
 */
function runMigrateScript(string ...$args): array
{
    $command = [PHP_BINARY, dirname(__DIR__) . '/migrations/migrate.php', ...array_values($args)];
    $env = TestSite::$env + ['PATH' => (string) getenv('PATH')];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start migrate.php');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

testBothDrivers('the command line applies a pending migration, and --check sees it', function (string $driver) {
    $pending = siteMissingNewestMigration($driver);

    [$code, $output] = runMigrateScript('--check');
    assertEquals(1, $code, '--check with work pending exits 1');
    assertContains($pending, $output, '--check names the pending file');

    [$code, $output] = runMigrateScript();
    assertEquals(0, $code, "applying exits 0 (said: {$output})");
    assertContains('Applied: ' . $pending, $output, 'it names what it applied');

    [$code, $output] = runMigrateScript('--check');
    assertEquals(0, $code, '--check after applying exits 0');
    assertContains('Up to date', $output, 'and says so');
});

test('the command line refuses a site that is not installed', function () {
    installedSite(['en' => 'English']);
    unlink(TestSite::$env['STORAGE_PATH'] . '/install.lock');

    [$code, $output] = runMigrateScript();
    assertEquals(1, $code, 'an uninstalled site exits 1');
    assertContains('not installed', $output, 'and says why');
});
