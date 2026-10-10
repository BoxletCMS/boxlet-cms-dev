<?php

/*
 * WHAT SCENARIOS NEED OF THE COPY, PREPARED (PLAN.md D-174). Run by sync-copy.sh after the
 * code and the migrations. NOT part of Boxlet: a suite tool, run against the copy only.
 *
 *   php prepare-copy.php <copy directory> <checkout>
 *
 * Every scenario runs on the copy since the owner's rule of 2026-10-02, and four had relied
 * on what only the development site had. Each want is met through the application's own code
 * — its upload, its variants, its geo install, its tracker — never by writing rows by hand,
 * and only when it is missing, so a sync over a prepared copy changes nothing:
 *
 *   - the country database (38-stats): the checkout's own, put in use by Geo::install();
 *   - visits from more than one country in the last month (38-stats): one view each from a
 *     handful of public addresses, counted by Tracker::record() as a visitor's would be;
 *   - more pictures than one window shows (52-row-menu): plain coloured pictures made here,
 *     so none is a photograph another scenario uploads and checks for as new.
 *
 * A copy that is not installed yet has nothing to prepare: 01-install comes first.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php prepare-copy.php <copy directory> <checkout>\n");
    exit(2);
}
$site = rtrim($argv[1], '/');
$checkout = rtrim($argv[2], '/');
if (!is_file($site . '/storage/install.lock')) {
    echo "Copy not installed yet: nothing to prepare.\n";
    exit(0);
}

chdir($site);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => '8100', 'REMOTE_ADDR' => '127.0.0.1'];
require $site . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($site)->safeLoad();
/** @var App\Core\Container $container */
$container = require $site . '/app/bootstrap.php';
$db = $container->get('db');
$storage = (string) $container->get('config')->get('app.storage_path');

// ---- the country database ------------------------------------------------------------------
$source = $checkout . '/storage/geo/dbip-country-lite.mmdb';
if (App\Modules\Stats\Geo::status($storage) === null && is_file($source)) {
    App\Modules\Stats\Geo::install($storage, $source);
    echo "Country database put in use.\n";
}

// ---- visits from more than one country, this month -------------------------------------------
$since = (new DateTimeImmutable('-29 days'))->format('Y-m-d');
$countries = (int) ($db->one("SELECT COUNT(DISTINCT country) AS n FROM stats_views WHERE day >= ? AND country <> ''", [$since])['n'] ?? 0);
if ($countries < 2) {
    // Public resolvers and well-known hosts in different countries; what each resolves to is
    // the database's answer, and only that more than one country results is asked.
    foreach (['8.8.8.8', '1.1.1.1', '9.9.9.9', '193.198.184.1', '80.80.80.80', '84.200.69.80'] as $at => $ip) {
        $request = new App\Core\Request('GET', '/', '', [], [], [
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0 prepare-' . $at,
            'host' => '127.0.0.1:8100',
        ], $ip);
        $response = new App\Core\Response('<!doctype html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
        App\Modules\Stats\Tracker::record($db, $request, $response, null, $storage);
    }
    $now = (int) ($db->one("SELECT COUNT(DISTINCT country) AS n FROM stats_views WHERE day >= ? AND country <> ''", [$since])['n'] ?? 0);
    echo "Visits recorded from {$now} countries.\n";
}

// ---- more pictures than one window shows -------------------------------------------------------
$wanted = 10;
$have = (int) ($db->one("SELECT COUNT(*) AS n FROM media WHERE mime LIKE 'image/%'")['n'] ?? 0);
if ($have < $wanted && function_exists('imagecreatetruecolor')) {
    $upload = $container->get('media_upload');
    $variants = $container->get('media_variants');
    for ($n = $have; $n < $wanted; $n++) {
        $image = imagecreatetruecolor(1200, 800);
        $colour = imagecolorallocate($image, (37 * $n + 60) % 256, (91 * $n + 120) % 256, (53 * $n + 180) % 256);
        imagefilledrectangle($image, 0, 0, 1199, 799, $colour);
        $ink = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 40, 40, 'Suite sample ' . $n . ' ' . bin2hex(random_bytes(4)), $ink);
        $file = tempnam(sys_get_temp_dir(), 'suite');
        imagejpeg($image, $file, 85);
        imagedestroy($image);
        $stored = $upload->store($file, 'suite-sample-' . $n . '.jpg');
        @unlink($file);
        $variants->generate((int) $stored['id'], 120.0);
    }
}
// Said whether any were made or not: the demo brings fifty of its own since D-213, and a count
// printed only when pictures were added read as a copy left unprepared.
echo 'Pictures in the library: ' . (int) ($db->one("SELECT COUNT(*) AS n FROM media WHERE mime LIKE 'image/%'")['n'] ?? 0) . ".\n";
