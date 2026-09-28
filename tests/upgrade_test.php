<?php

use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Update\Maintenance;
use App\Modules\Update\Package;
use App\Modules\Update\Upgrade;
use App\Support\ZipWriter;

// Updating to a new version (PLAN.md D-140), on a site made up in a folder of the test's own:
// code of a pretend old version, the site's own things beside it, and a package of a pretend
// new one that brings a migration. Nothing here touches the checkout's code.

const UPGRADE_MIGRATION = '9001_upgrade_probe.sql';

/**
 * The made-up site and its database. Its migrations are the real ones, so the database the
 * test suite makes fits it.
 *
 * @return array{string, Db} the site's root and its database
 */
function oldSite(): array
{
    $root = tmpPath('upgrade-site');
    removeTree($root);
    foreach (['app', 'vendor', 'migrations', 'public/m/hero', 'public/cache', 'storage/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0700, true);
    }
    file_put_contents($root . '/VERSION', "v0.1.0\n");
    file_put_contents($root . '/app/old-only.php', '<?php // old');
    file_put_contents($root . '/app/bootstrap.php', '<?php // old bootstrap');
    file_put_contents($root . '/vendor/autoload.php', '<?php // old vendor');
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
        copy($file, $root . '/migrations/' . basename($file));
    }
    file_put_contents($root . '/public/index.php', '<?php // old index');
    file_put_contents($root . '/public/m/hero/1-photo.avif', 'the site\'s picture');
    file_put_contents($root . '/public/cache/tokens.css', 'the site\'s cache');
    file_put_contents($root . '/storage/uploads/photo.jpg', 'the original');
    file_put_contents($root . '/.env', "APP_KEY=\"site\"\n");
    // The owner's line above Boxlet's block, and a root .htaccess of the owner's own.
    file_put_contents($root . '/public/.htaccess', "# mine\nPhp_value upload_max_filesize 64M\n# BEGIN Boxlet\nold rules\n# END Boxlet\n# mine too\n");
    file_put_contents($root . '/.htaccess', "AddHandler application/x-httpd-ea-php81 .php\n");

    $db = installedSite(['en' => 'English'], 'sqlite');

    return [$root, $db];
}

/** A package of $version, as build.php makes one, with one migration the old site lacks. */
function newPackage(string $path, string $version): void
{
    $zip = new ZipWriter($path);
    $zip->addString('boxlet/VERSION', $version . "\n");
    $zip->addString('boxlet/app/bootstrap.php', '<?php // new bootstrap');
    $zip->addString('boxlet/app/new-only.php', '<?php // new');
    $zip->addString('boxlet/vendor/autoload.php', '<?php // new vendor');
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
        $zip->addFile('boxlet/migrations/' . basename($file), $file);
    }
    $zip->addString('boxlet/migrations/' . UPGRADE_MIGRATION, "CREATE TABLE upgrade_probe (\n    id {{pk}}\n);\n");
    $zip->addString('boxlet/public/index.php', '<?php // new index');
    $zip->addString('boxlet/public/install.php', '<?php // the installer');
    $zip->addString('boxlet/public/m/.gitkeep', '');
    $zip->addString('boxlet/public/.htaccess', "# BEGIN Boxlet\nnew rules\n# END Boxlet\n");
    $zip->addString('boxlet/.htaccess', "# BEGIN Boxlet\nroot rules\n# END Boxlet\n");
    $zip->addString('boxlet/storage/.htaccess', 'Require all denied');
    $zip->finish();
}

test('a package is refused when it is not a newer release', function () {
    $dir = tmpPath('packages');
    removeTree($dir);
    mkdir($dir, 0700, true);
    newPackage($dir . '/good.zip', 'v0.2.0');
    assertEquals('v0.2.0', Package::check($dir . '/good.zip', 'v0.1.0'), 'a newer release');

    assertThrows(static fn () => Package::check($dir . '/good.zip', 'v0.2.0'), 'v0.2.0');
    assertThrows(static fn () => Package::check($dir . '/good.zip', 'v0.3.0'), 'v0.3.0');
    assertThrows(static fn () => Package::check($dir . '/good.zip', 'development'), t('updates.development'));
    file_put_contents($dir . '/text.zip', 'not a zip');
    assertThrows(static fn () => Package::check($dir . '/text.zip', 'v0.1.0'), t('updates.not_a_zip'));

    $climbs = new ZipWriter($dir . '/climbs.zip');
    $climbs->addString('boxlet/VERSION', 'v9.0.0');
    $climbs->addString('outside.php', '<?php');
    $climbs->finish();
    assertThrows(static fn () => Package::check($dir . '/climbs.zip', 'v0.1.0'), 'outside.php');

    $half = new ZipWriter($dir . '/half.zip');
    $half->addString('boxlet/VERSION', 'v9.0.0');
    $half->finish();
    assertThrows(static fn () => Package::check($dir . '/half.zip', 'v0.1.0'), t('updates.not_a_release'));
    removeTree($dir);
});

test('what a package never writes: the site\'s own folders, its settings and the installer', function () {
    foreach (['boxlet/storage/.htaccess', 'boxlet/public/m/x.avif', 'boxlet/public/cache/t.css', 'boxlet/public/install.php', 'boxlet/.env', 'boxlet/app/'] as $name) {
        assertEquals(null, Package::target($name), $name);
    }
    assertEquals('app/bootstrap.php', Package::target('boxlet/app/bootstrap.php'), 'code');
    assertEquals('public/index.php', Package::target('boxlet/public/index.php'), 'the front controller');
    assertEquals('.htaccess', Package::target('boxlet/.htaccess'), 'the root rules');
});

test('an update swaps the code, keeps the site\'s own things, runs the new migration, and rolls back', function () {
    [$root, $db] = oldSite();
    $storage = $root . '/storage';
    $upgrade = new Upgrade($db, $root, $storage, $root . '/public/cache', $root . '/public');
    mkdir($upgrade->dir(), 0770, true);
    newPackage($upgrade->dir() . '/package.zip', 'v0.2.0');

    $upgrade->start('v0.2.0', 'v0.1.0', '2026-09-28-120000-update');
    assertTrue((new Maintenance($storage))->isOn(), 'maintenance is off during the update');
    $steps = 0;
    do {
        $result = $upgrade->step(microtime(true)); // no time at all: one piece a step
        $steps++;
    } while (!$result['done'] && $steps < 200);

    assertTrue($result['done'], 'never done');
    assertTrue($steps > 4, "{$steps} steps");
    assertEquals("v0.2.0\n", (string) file_get_contents($root . '/VERSION'), 'VERSION');
    assertEquals('<?php // new bootstrap', (string) file_get_contents($root . '/app/bootstrap.php'), 'the code');
    assertTrue(is_file($root . '/app/new-only.php') && !is_file($root . '/app/old-only.php'), 'app/ is the new one whole');
    assertEquals('<?php // new vendor', (string) file_get_contents($root . '/vendor/autoload.php'), 'vendor');
    assertEquals('<?php // new index', (string) file_get_contents($root . '/public/index.php'), 'index.php');
    assertTrue(!is_file($root . '/public/install.php'), 'the installer came back');

    assertEquals('the site\'s picture', (string) file_get_contents($root . '/public/m/hero/1-photo.avif'), 'a picture');
    assertEquals('the original', (string) file_get_contents($root . '/storage/uploads/photo.jpg'), 'an original');
    assertEquals("APP_KEY=\"site\"\n", (string) file_get_contents($root . '/.env'), '.env');
    assertTrue(!is_file($root . '/storage/.htaccess'), 'storage/ was written into');

    assertEquals("# mine\nPhp_value upload_max_filesize 64M\n# BEGIN Boxlet\nnew rules\n# END Boxlet\n# mine too\n", (string) file_get_contents($root . '/public/.htaccess'), 'public/.htaccess merged');
    assertEquals("AddHandler application/x-httpd-ea-php81 .php\n", (string) file_get_contents($root . '/.htaccess'), 'an owner\'s own root .htaccess kept');
    assertEquals("# BEGIN Boxlet\nroot rules\n# END Boxlet\n", (string) file_get_contents($root . '/.htaccess.boxlet-new'), 'the new rules beside it');
    assertEquals([t('updates.htaccess_kept', ['file' => '.htaccess'])], $upgrade->state()['warnings'] ?? null, 'the owner is told');

    assertTrue(in_array(UPGRADE_MIGRATION, (new Migrator($db, $root . '/migrations'))->available(), true), 'the migration arrived');
    assertEquals([], (new Migrator($db, $root . '/migrations'))->pending(), 'pending migrations');
    $db->query('SELECT COUNT(*) FROM upgrade_probe'); // the new table is there
    assertTrue(!(new Maintenance($storage))->isOn(), 'maintenance left on');
    assertTrue(!is_dir($upgrade->dir() . '/new') && !is_file($upgrade->dir() . '/package.zip'), 'the unpacked package left behind');

    $upgrade->rollBack();
    assertEquals("v0.1.0\n", (string) file_get_contents($root . '/VERSION'), 'VERSION after rolling back');
    assertTrue(is_file($root . '/app/old-only.php') && !is_file($root . '/app/new-only.php'), 'app/ after rolling back');
    assertEquals('<?php // old index', (string) file_get_contents($root . '/public/index.php'), 'index.php after rolling back');
    assertEquals("# mine\nPhp_value upload_max_filesize 64M\n# BEGIN Boxlet\nold rules\n# END Boxlet\n# mine too\n", (string) file_get_contents($root . '/public/.htaccess'), 'public/.htaccess after rolling back');
    assertEquals('the site\'s picture', (string) file_get_contents($root . '/public/m/hero/1-photo.avif'), 'a picture after rolling back');
    assertEquals('rolled-back', $upgrade->state()['phase'] ?? null, 'phase');
    removeTree($root);
});

test('a .htaccess a release shipped before the markers is replaced whole', function () {
    [$root, $db] = oldSite();
    // public/.htaccess exactly as v0.1.0-preview.2 shipped it.
    copy(__DIR__ . '/fixtures/public-htaccess-v0.1.0-preview.2', $root . '/public/.htaccess');
    $upgrade = new Upgrade($db, $root, $root . '/storage', $root . '/public/cache', $root . '/public');
    mkdir($upgrade->dir(), 0770, true);
    newPackage($upgrade->dir() . '/package.zip', 'v0.2.0');
    $upgrade->start('v0.2.0', 'v0.1.0', 'x');
    do {
        $result = $upgrade->step(microtime(true) + 60);
    } while (!$result['done']);

    assertEquals("# BEGIN Boxlet\nnew rules\n# END Boxlet\n", (string) file_get_contents($root . '/public/.htaccess'), 'replaced whole');
    removeTree($root);
});
