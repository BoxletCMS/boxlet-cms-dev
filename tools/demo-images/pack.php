<?php

/*
 * THE DEMO'S PACKAGE (PLAN.md D-215): every picture the demo's pages name, with every size of
 * it already made, its documents and their words, in one archive the installer downloads from
 * demo_images/ on GitHub and copies into place. Run by hand, never by the site:
 *
 *   php tools/demo-images/pack.php
 *
 * writes demo_images/boxlet-demo-<VERSION>.zip and prints its size and SHA-256, which go into
 * InstallDemoPackage::PACKAGE. A released package is never rebuilt under its name: a new demo
 * is the next VERSION, and the old file stays. The sizes are made by Boxlet's own pipeline (MediaUpload,
 * MediaVariants) on a database and folders of its own, exactly as an install would make them,
 * so an install that copies them has the same files and the same rows as one that made them.
 * Making them here took 260 seconds; on the owner's host, minutes more than a request has.
 *
 * Layout of the archive (stored, not compressed: the pictures are compressed already, and a
 * stored zip opens with PharData where ZipArchive is missing):
 *   credits.json          the pictures' credits and alt texts, as demo_images/ has it
 *   space/…, art/…        the originals, at the paths credits.json names
 *   files/…               the documents, one per language
 *   prepared/m/<preset>/<id>-<name>.<format>   every size, under the id it had here
 *   prepared/manifest.json   file => the id here, the stored name, and variants_json
 */

use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Install\InstallDemo;
use App\Modules\Install\InstallDemoPackage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$version = InstallDemoPackage::PACKAGE['version'];
$zipPath = "{$root}/demo_images/boxlet-demo-{$version}.zip";
if (is_file($zipPath)) {
    fwrite(STDERR, "{$zipPath} is released already: a new demo is the next version.\n");
    exit(1);
}
$work = sys_get_temp_dir() . '/boxlet-demo-pack-' . getmypid();
@mkdir($work . '/storage', 0775, true);
@mkdir($work . '/public', 0775, true);
$db = new Db('sqlite', 'sqlite:' . $work . '/pack.sqlite');
(new Migrator($db, $root . '/migrations'))->migrate();

// Both languages name the same pictures; taken once each.
$files = array_values(array_unique(array_merge(InstallDemo::pictures('en'), InstallDemo::pictures('hr'))));
$store = InstallDemo::storer($db, $work . '/storage', $work . '/public');
$manifest = [];
$started = microtime(true);
foreach ($files as $file) {
    $made = $store($file, basename($file), 600.0);
    if (!$made['complete']) {
        fwrite(STDERR, "Unfinished: {$file}\n");
        exit(1);
    }
    $row = $db->one('SELECT filename, variants_json FROM media WHERE id = ?', [$made['id']]);
    $relative = substr($file, strlen($root . '/demo_images/'));
    $manifest[$relative] = ['id' => $made['id'], 'filename' => (string) ($row['filename'] ?? ''), 'variants_json' => (string) ($row['variants_json'] ?? '')];
    printf("  %-58s %3d sizes\n", $relative, $made['made']);
}
printf("Made in %.0f s.\n", microtime(true) - $started);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot write {$zipPath}\n");
    exit(1);
}
$add = static function (string $from, string $as) use ($zip): void {
    $zip->addFile($from, $as);
    $zip->setCompressionName($as, ZipArchive::CM_STORE);
};
$add($root . '/demo_images/credits.json', 'credits.json');
foreach (array_keys($manifest) as $relative) {
    $add($root . '/demo_images/' . $relative, $relative);
}
foreach (glob($root . '/demo_images/files/*.pdf') ?: [] as $pdf) {
    $add($pdf, 'files/' . basename($pdf));
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work . '/public/m', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $variant) {
    $add($variant->getPathname(), 'prepared/' . substr($variant->getPathname(), strlen($work . '/public/')));
}
$zip->addFromString('prepared/manifest.json', json_encode(['version' => $version, 'pictures' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();

exec('rm -rf ' . escapeshellarg($work));
printf("\n%s\n%d bytes, sha256 %s\n", $zipPath, filesize($zipPath), hash_file('sha256', $zipPath));
