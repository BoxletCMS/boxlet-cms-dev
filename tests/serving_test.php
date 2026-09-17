<?php

// Serving without PHP, identically on nginx and Apache (PLAN.md D-020).
//
// The rule these stand over: anything served without PHP is a real file at its own URL
// under public/, and nothing else under public/ is executable. Both servers serve an
// existing file before they reach a rewrite, so no server-specific configuration is
// needed for the compiled stylesheet or the media variants.

test('nothing under public/ is executable except the two entry points', function () {
    $root = dirname(__DIR__);
    $allowed = ['index.php', 'install.php'];

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/public', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        // The page cache writes rendered HTML here from Slice 8; it is generated, not
        // shipped, and is excluded from the repository.
        $relative = substr($file->getPathname(), strlen($root . '/public/'));
        if (str_starts_with($relative, 'cache/')) {
            continue;
        }
        if (preg_match('~\.(php\d?|phtml|phps|cgi|pl|py|jsp|asp|sh)$~i', $relative)) {
            $found[] = $relative;
        }
    }

    sort($found);
    // An upload folder under public/ is the case this rule exists for: on nginx an
    // .htaccess does nothing, so a script that reached a public directory would run.
    // Originals live in storage/uploads/ instead, where nothing is served (D-020).
    assertEquals($allowed, $found, 'executable files under public/');
});

test('original uploads are outside the web root, and only variants are public', function () {
    $root = dirname(__DIR__);

    assertTrue(!is_dir($root . '/public/uploads'), 'public/uploads exists again');
    assertTrue(is_dir($root . '/storage/uploads'), 'storage/uploads is missing');

    // The same deny file the rest of storage/ carries: a safety net on Apache shared
    // hosting where the document root cannot be moved. On nginx it does nothing, which is
    // exactly why the directory is outside public/ rather than protected inside it.
    assertTrue(is_file($root . '/storage/uploads/.htaccess'), 'storage/uploads has no deny file');
    assertContains('Require all denied', (string) file_get_contents($root . '/storage/uploads/.htaccess'), 'the deny rule');
});

// Slug::SYSTEM keeps "uploads" reserved even though /uploads/ is no longer a public
// directory, and the reason is recorded on the constant itself rather than asserted here.
// A test that in_array()s a literal against a compile-time constant cannot fail — PHPStan
// says so outright — so it reads as coverage while proving nothing.
