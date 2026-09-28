<?php

use App\Support\ZipWriter;

// The ZIP a backup is written as (PLAN.md D-139), checked by what reads it back: PHP's own
// ZipArchive, which a restore uses, and `unzip -t` where the machine has it, a reader that
// shares no code with either.

/**
 * @return array<string, string> every entry's name and contents, as ZipArchive reads them
 */
function zipContents(string $path): array
{
    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CHECKCONS);
    $opened === true || fail("ZipArchive cannot open {$path}: {$opened}");
    $found = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $found[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $found;
}

/** What `unzip -t` says about $path, or null where there is no unzip. */
function unzipSays(string $path): ?string
{
    if (trim((string) shell_exec('command -v unzip')) === '') {
        return null;
    }

    return (string) shell_exec('unzip -t ' . escapeshellarg($path) . ' 2>&1');
}

test('entries written across several requests read back whole, stored and deflated alike', function () {
    $dir = tmpPath('zip-writer');
    removeTree($dir);
    mkdir($dir, 0700, true);
    $big = random_bytes(3 * 1024 * 1024 + 17); // more than one chunk, and not compressible
    file_put_contents($dir . '/photo.avif', $big);
    file_put_contents($dir . '/rows.ndjson', str_repeat("{\"id\":1,\"title\":\"Hello\"}\n", 5000));
    $path = $dir . '/backup.zip';

    $first = new ZipWriter($path);
    $first->addString('manifest.json', '{"version":"v1"}');
    $first->addFile('public/m/hero/1-photo.avif', $dir . '/photo.avif');
    $checkpoint = $first->checkpoint();

    // The next request carries on from what the first one handed over.
    $second = new ZipWriter($path, $checkpoint);
    $second->addFile('database/pages.ndjson', $dir . '/rows.ndjson');
    $second->addString('storage/uploads/čćžšđ.txt', 'names are UTF-8');
    $checkpoint = $second->checkpoint();

    $third = new ZipWriter($path, $checkpoint);
    $size = $third->finish();

    assertEquals((int) filesize($path), $size, 'the size finish() reports');
    $read = zipContents($path);
    assertEquals(['manifest.json', 'public/m/hero/1-photo.avif', 'database/pages.ndjson', 'storage/uploads/čćžšđ.txt'], array_keys($read), 'entries');
    assertTrue($read['public/m/hero/1-photo.avif'] === $big, 'the picture came back different');
    assertEquals((string) file_get_contents($dir . '/rows.ndjson'), $read['database/pages.ndjson'], 'rows');
    assertTrue($size < strlen($big) + 100_000, 'the rows were not deflated');
    $unzip = unzipSays($path);
    if ($unzip !== null) {
        assertContains('No errors detected', $unzip, 'unzip -t');
    }
    assertTrue(!is_file($path . '.directory'), 'the directory file was left behind');
    removeTree($dir);
});

test('what a killed request wrote after its last checkpoint is cut off, and the archive stays whole', function () {
    $dir = tmpPath('zip-writer-cut');
    removeTree($dir);
    mkdir($dir, 0700, true);
    $path = $dir . '/backup.zip';

    $writer = new ZipWriter($path);
    $writer->addString('kept.txt', 'kept');
    $checkpoint = $writer->checkpoint();
    // Written, and then the request dies before the next checkpoint is kept.
    $writer->addString('lost.txt', str_repeat('lost', 1000));
    unset($writer);

    $again = new ZipWriter($path, $checkpoint);
    $again->addString('after.txt', 'after');
    $again->finish();

    assertEquals(['kept.txt' => 'kept', 'after.txt' => 'after'], zipContents($path), 'entries');
    removeTree($dir);
});

test('past 65,535 entries the archive is a ZIP64 that still reads back', function () {
    $dir = tmpPath('zip-writer-64');
    removeTree($dir);
    mkdir($dir, 0700, true);
    $path = $dir . '/many.zip';

    $writer = new ZipWriter($path);
    for ($i = 0; $i < 65_540; $i++) {
        $writer->addString("m/{$i}.txt", (string) $i);
    }
    $writer->finish();

    $zip = new ZipArchive();
    assertEquals(true, $zip->open($path, ZipArchive::CHECKCONS), 'ZipArchive opens it');
    assertEquals(65_540, $zip->numFiles, 'entries');
    assertEquals('65539', (string) $zip->getFromName('m/65539.txt'), 'the last entry');
    $zip->close();
    $unzip = unzipSays($path);
    if ($unzip !== null) {
        assertContains('No errors detected', $unzip, 'unzip -t');
    }
    removeTree($dir);
});

test('a name that climbs out of the archive is refused', function () {
    $writer = new ZipWriter(tmpPath('zip-writer-name.zip'));
    assertThrows(static fn () => $writer->addString('../outside.txt', 'x'), 'Not a name');
    assertThrows(static fn () => $writer->addString('a/../../outside.txt', 'x'), 'Not a name');
    unset($writer);
    unlink(tmpPath('zip-writer-name.zip.directory'));
    unlink(tmpPath('zip-writer-name.zip'));
});
