<?php

// Nothing in app/ may be deprecated on the PHP it runs on (PLAN.md O-20).
//
// The suite already fails a test on any notice, warning or deprecation — but only where one
// is RAISED while that test runs, and a deprecation raised as a class is first loaded can be
// swallowed by whatever catch happens to be on the stack. That is how an implicitly nullable
// parameter reached CI green-locally and red there, on PHP 8.4, in September 2026.
//
// So: load every class file in a process of its own, with nothing catching anything, and
// read what PHP says about it.

test('no file in app/ is deprecated on this PHP', function () {
    $root = dirname(__DIR__);
    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        $path = (string) $file;
        // Class files only: a template expects its variables and would run here, which
        // proves nothing about anything — app/Chrome/*/template.php drew a footer and then
        // died on a missing $locales.
        if (str_ends_with($path, '.php') && !str_contains($path, '/views/')
            && !str_contains($path, '/Blocks/') && basename($path) !== 'template.php') {
            $files[] = $path;
        }
    }
    sort($files);
    assertTrue(count($files) > 50, 'only ' . count($files) . ' files found to load');

    $script = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . 'foreach (' . var_export($files, true) . ' as $file) { require_once $file; }';
    $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d display_errors=stderr -r ' . escapeshellarg($script) . ' 2>&1';
    $said = (string) shell_exec($command);

    assertEquals('', trim($said), 'PHP said this while loading app/');
});
