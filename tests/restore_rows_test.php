<?php

use App\Core\Settings;
use App\Modules\Backup\RestoreRows;

// A TABLE LOADED BY A RESTORE IS THE BACKUP'S (PLAN.md D-249): every step of a restore is a
// request of its own, and a request may write a row between the step that emptied a table and the
// one that loads it. The router compiles the stylesheet when settings has none, and records
// tokens_code; loading settings then stopped on a duplicate key, on MySQL, in the full suite.

testBothDrivers('a row written between a restore\'s steps does not stop the table loading', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $work = tmpPath('restore-rows');
    if (!is_dir($work)) {
        mkdir($work, 0700, true);
    }
    $zipPath = $work . '/backup.zip';
    if (is_file($zipPath)) {
        unlink($zipPath);
    }
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('database/settings.ndjson',
        json_encode(['key' => 'tokens_code', 'value_json' => json_encode('from the backup')]) . "\n"
        . json_encode(['key' => 'site_name', 'value_json' => json_encode('Backed up')]) . "\n");
    $zip->close();

    // What a request did after the table was emptied: the stylesheet compiled, its code recorded.
    $db->query('DELETE FROM settings');
    Settings::set($db, 'tokens_code', 'written by a request');

    $zip = new ZipArchive();
    $zip->open($zipPath);
    $next = (new RestoreRows($db, dirname(__DIR__), $work))->piece($zip, 'settings', 0);
    $zip->close();

    assertEquals(null, $next, 'the table is all in');
    assertEquals('from the backup', Settings::get($db, 'tokens_code'), 'the backup\'s row, not the request\'s');
    assertEquals('Backed up', Settings::get($db, 'site_name'), 'and the rest of it');
});
