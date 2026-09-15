<?php

use App\Core\RewriteCheck;

// URL rewriting is required. Apache without mod_rewrite is caught by the .htaccess
// marker in public/index.php; everything else by RewriteCheck in the installer.

test('REDIRECT_STATUS=404 shows the rewriting-required page before anything boots', function () {
    // Run public/index.php in a separate CLI process, where environment variables land
    // in $_SERVER just as Apache's ErrorDocument sets REDIRECT_STATUS.
    $env = ['REDIRECT_STATUS' => '404', 'REQUEST_URI' => '/hello'] + getenv();
    $command = [PHP_BINARY, dirname(__DIR__) . '/public/index.php'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) {
        fail('could not start PHP');
    }
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    assertEquals('', $errors, 'stderr');
    assertContains('<h1>URL rewriting is required</h1>', $output);
    assertContains('RewriteRule ^ index.php [QSA,L]', $output);
    assertContains('try_files $uri $uri/ /index.php?$query_string;', $output);
});

test('the probe route answers with the RewriteCheck token', function () {
    $response = dispatch(RewriteCheck::PROBE_PATH);
    assertEquals(200, $response->status, 'status');
    assertEquals(RewriteCheck::TOKEN, $response->body, 'body');
});

test('RewriteCheck reports false when nothing answers', function () {
    assertTrue(!RewriteCheck::works('http://127.0.0.1:9', 1.0), 'works() returned true for a closed port');
});
