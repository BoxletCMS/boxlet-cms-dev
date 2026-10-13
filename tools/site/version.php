<?php

/**
 * BOXLETCMS.ORG SAYS WHICH VERSION IS OUT (PLAN.md D-244): the `version` snippet, which the
 * Download page shows ("Version {{snippet:version}}"), set to the release just published. The
 * seed wrote v0.1.0-rc.4 and nothing moved it on; on 2026-10-13 it still said rc.4 with rc.9 out.
 * Run after each release, through the site's own code:
 *
 *   php tools/site/version.php <site root> <site address> <version>
 */

use App\Modules\Snippets\Snippets;
use App\Support\PageCache;

if (PHP_SAPI !== 'cli' || $argc !== 4 || preg_match('~^v\d+\.\d+\.\d+(-[a-z]+\.\d+)?$~', $argv[3]) !== 1) {
    fwrite(STDERR, "Usage: php tools/site/version.php <site root> <site address> <version, such as v0.1.0-rc.9>\n");
    exit(1);
}
[, $site, $address, $version] = $argv;
$site = rtrim((string) realpath($site), '/');
$origin = parse_url($address);
if (!is_file($site . '/app/bootstrap.php') || !is_array($origin) || !isset($origin['host'])) {
    fwrite(STDERR, "Not a Boxlet install, or not a full site address.\n");
    exit(1);
}
$https = ($origin['scheme'] ?? '') === 'https';
chdir($site);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'SERVER_NAME' => $origin['host'], 'SERVER_PORT' => (string) ($origin['port'] ?? ($https ? 443 : 80)), 'HTTPS' => $https ? 'on' : 'off', 'REMOTE_ADDR' => '127.0.0.1'];
require $site . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($site)->safeLoad();
/** @var App\Core\Container $container */
$container = require $site . '/app/bootstrap.php';
$container->get('router');
$db = $container->get('db');
$locale = (string) ($db->one('SELECT code FROM locales WHERE is_primary = 1')['code'] ?? '');

$was = Snippets::forLocale($db, $locale, $locale)['version'] ?? '(none)';
Snippets::save($db, 'version', [$locale => $version]);
PageCache::clear();
echo "version: {$was} → {$version}; kept pages emptied.\n";
