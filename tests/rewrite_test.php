<?php

use App\Core\RewriteCheck;

// URL rewriting is required. Apache without mod_rewrite is caught by the .htaccess
// marker in public/index.php; everything else by RewriteCheck in the installer.

/**
 * Runs public/index.php in its own CLI process, where environment variables land in
 * $_SERVER as a web server would set them. A prepended shutdown hook appends the
 * response status, which the CLI would otherwise not show.
 *
 * @param array<string, string> $server
 * @return array{string, string, int} stdout, stderr, status
 */
function runIndex(array $server): array
{
    $prepend = tmpPath('status-prepend.php');
    file_put_contents($prepend, '<?php register_shutdown_function(static function (): void { echo "\nSTATUS:", http_response_code(); });');
    $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, dirname(__DIR__) . '/public/index.php'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $server + getenv());
    if (!is_resource($process)) {
        fail('could not start PHP');
    }
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $status = preg_match('~\nSTATUS:(\d+)$~', $output, $match) ? (int) $match[1] : 0;

    return [(string) preg_replace('~\nSTATUS:\d+$~', '', $output), $errors, $status];
}

test('REDIRECT_STATUS=404 shows the rewriting-required page before anything boots', function () {
    [$output, $errors, $status] = runIndex(['REDIRECT_STATUS' => '404', 'REQUEST_URI' => '/hello']);

    assertEquals('', $errors, 'stderr');
    assertEquals(500, $status, 'status');
    assertContains('<h1>URL rewriting is required</h1>', $output);
    assertContains('RewriteRule ^ index.php [QSA,L]', $output);
    assertContains('try_files $uri $uri/ /index.php?$query_string;', $output);
});

test('the probe is answered before bootstrapping, so it works before install', function () {
    $emptyStorage = tmpPath('no-install');
    if (!is_dir($emptyStorage)) {
        mkdir($emptyStorage, 0700, true);
    }
    [$output, $errors, $status] = runIndex([
        'REQUEST_URI' => RewriteCheck::PROBE_PATH,
        'SCRIPT_NAME' => '/index.php',
        'STORAGE_PATH' => $emptyStorage,
    ]);

    assertEquals('', $errors, 'stderr');
    assertEquals(200, $status, 'status');
    assertEquals(RewriteCheck::TOKEN, $output, 'body');
});

test('a site that is not installed redirects to install.php', function () {
    $emptyStorage = tmpPath('no-install');
    if (!is_dir($emptyStorage)) {
        mkdir($emptyStorage, 0700, true);
    }
    [$output, $errors, $status] = runIndex([
        'REQUEST_URI' => '/hello',
        'SCRIPT_NAME' => '/index.php',
        'STORAGE_PATH' => $emptyStorage,
    ]);

    assertEquals('', $errors, 'stderr');
    assertEquals(302, $status, 'status');
    assertEquals('', $output, 'body');
});

test('RewriteCheck reports false when nothing answers', function () {
    assertTrue(!RewriteCheck::works('http://127.0.0.1:9', 1.0), 'works() returned true for a closed port');
});
