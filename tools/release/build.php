<?php

/**
 * Builds the release ZIP: the one form of Boxlet a person without a shell can install
 * (PLAN.md D-056, SPEC §8 Slice 9).
 *
 *   php tools/release/build.php [target directory] [version]
 *
 * With a version (v0.1.0-preview) the package is named boxlet-v0.1.0-preview.zip, which is
 * what the release workflow in BoxletCMS/Boxlet-CMS asks for; without one it is named by
 * the day and the commit, for a package tried by hand.
 *
 * WHY THIS EXISTS. The source on GitHub has no vendor/ — it is in .gitignore, because a
 * repository that commits 53 MB of libraries turns a version bump into a diff nobody can
 * read. So a checkout cannot be installed by anyone who cannot run composer, which is
 * exactly the person Boxlet is for: no root, no SSH, an FTP client and a browser. This
 * script is the missing step. Composer runs HERE, once per release, and never on a user's
 * server.
 *
 * For maintainers only, like the icon sprite and the map beside it.
 *
 * WHAT GOES IN: everything git tracks, minus the parts that are the project's rather than
 * the product's, plus vendor/ built with --no-dev. WHAT STAYS OUT, and why each one:
 *   tests/, tools/, docs/, .github/, phpstan.neon   development, never on a user's server
 *   composer.json, composer.lock                    with them, `composer install` on a live
 *                                                   site would pull the dev tools back in
 *   CLAUDE.md, PLAN.md, .gitignore, .env.test.example   the project's papers, not the product's
 *   .env                                            not tracked at all; the installer writes it
 *
 * THE README in the package is tools/release/README.md, the one written for someone using
 * Boxlet; the checkout's own README.md is for someone working on it (D-137).
 *
 * It packs the LAST COMMIT, not the working tree, so a release is a thing that can be
 * pointed at afterwards. An uncommitted change is announced, not silently included.
 */

const KEEP_OUT = [
    'tests', 'tools', 'docs', '.github', 'phpstan.neon',
    'composer.json', 'composer.lock', 'CLAUDE.md', 'PLAN.md', '.gitignore', '.env.test.example',
];

/** What the ZIP must carry, checked after it is written rather than assumed. */
const MUST_HAVE = [
    'boxlet/public/install.php',
    'boxlet/public/index.php',
    'boxlet/.htaccess',
    'boxlet/public/.htaccess',
    'boxlet/vendor/autoload.php',
    'boxlet/storage/.htaccess',
    'boxlet/storage/uploads/.htaccess',
    'boxlet/public/assets/vendor/icons.svg',
    'boxlet/public/assets/vendor/world-map.svg',
    'boxlet/.env.example',
    'boxlet/README.md',
    'boxlet/LICENSE',
];

/** SPEC §1: the release is under this, or the fifteen-minute install is not honest. */
const SIZE_LIMIT = 8 * 1024 * 1024;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fail('PHP here has no zip extension, and this script needs one. It runs on a maintainer\'s machine, not on a user\'s server.');
}

$root = dirname(__DIR__, 2);
$target = rtrim($argv[1] ?? (getenv('HOME') . '/boxlet-releases'), '/');

$revision = run("git -C {$root} rev-parse --short HEAD");
$dirty = run("git -C {$root} status --porcelain") !== '';
if ($dirty) {
    // Said, not refused: a test package of work in progress is a reasonable thing to want.
    // What is not reasonable is believing it is the commit it is named after.
    fwrite(STDERR, "The working tree has uncommitted changes. THEY ARE NOT IN THIS PACKAGE:\n"
        . "it is built from the last commit, {$revision}.\n\n");
}

$stage = sys_get_temp_dir() . '/boxlet-release-' . getmypid();
$inside = $stage . '/boxlet';
removeTree($stage);
if (!mkdir($inside, 0755, true) && !is_dir($inside)) {
    fail("Cannot make {$inside}.");
}

// Every tracked file as the last commit has it: no .git, no editor leftovers, nothing the
// working tree happens to be carrying.
run("git -C {$root} archive HEAD | tar -x -C " . escapeshellarg($inside));

// The README a user reads is not the one a developer reads (PLAN.md D-137): the checkout's
// README.md is about working on Boxlet, tools/release/README.md about using it. The second
// takes the first's place in the package, and so in BoxletCMS/Boxlet-CMS, which a release
// fills from the package. Its pictures stay in tools/release/readme/, out of the package,
// and are linked from the development repository. Taken from the unpacked commit, not the
// working tree, like everything else in the package.
if (!rename($inside . '/tools/release/README.md', $inside . '/README.md')) {
    fail('Cannot put tools/release/README.md in the package.');
}

// Composer needs its two files; they are taken out again once it has run.
foreach (['composer.json', 'composer.lock'] as $file) {
    copy($root . '/' . $file, $inside . '/' . $file);
}
echo "Installing dependencies without the development tools…\n";
run('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir=' . escapeshellarg($inside) . ' 2>&1');

foreach (KEEP_OUT as $name) {
    removeTree($inside . '/' . $name);
}

$version = $argv[2] ?? '';
if ($version !== '' && preg_match('~^v\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$~', $version) !== 1) {
    fail("Not a version: {$version}. Expected something like v0.1.0 or v0.1.0-preview.");
}
$name = $version !== ''
    ? 'boxlet-' . $version . ($dirty ? '-from-a-dirty-tree' : '') . '.zip'
    : 'boxlet-' . gmdate('Y-m-d') . '-' . $revision . ($dirty ? '-from-a-dirty-tree' : '') . '.zip';
if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
    fail("Cannot make {$target}.");
}
$path = $target . '/' . $name;
@unlink($path);

$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE) !== true) {
    fail("Cannot write {$path}.");
}
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
$count = 0;
foreach ($files as $file) {
    /** @var SplFileInfo $file */
    $entry = substr($file->getPathname(), strlen($stage) + 1);
    // Modes set here rather than carried from this machine, where files came out rw-rw----
    // and folders rwxrwxrwx (D-138): unpacked on a cPanel host, the web server, which runs
    // as another user, could not read a stylesheet, and anyone could write to the folders.
    if ($file->isDir()) {
        $zip->addEmptyDir($entry);
        $zip->setExternalAttributesName($entry . '/', ZipArchive::OPSYS_UNIX, (0040755 << 16));
        continue;
    }
    $zip->addFile($file->getPathname(), $entry);
    $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, (0100644 << 16));
    $count++;
}
$zip->close();
removeTree($stage);

// Checked, not assumed. A release that is missing the installer, or carrying the tests, is
// worth finding out about here rather than on somebody's hosting.
$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fail('The package was written and cannot be opened again.');
}
$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entries[] = (string) $zip->getNameIndex($i);
}
$zip->close();

$missing = array_values(array_diff(MUST_HAVE, $entries));
$strays = array_values(array_filter($entries, static fn (string $e): bool
    => (bool) preg_match('~^boxlet/(tests|tools|docs|\.github|\.git/|vendor/phpstan)|^boxlet/(composer\.(json|lock)|phpstan\.neon|\.env$|CLAUDE\.md|PLAN\.md)~', $e)));

$size = (int) filesize($path);
printf("\n%s\n%d files, %.1f MB%s\n", $path, $count, $size / 1048576, $size > SIZE_LIMIT ? ' — OVER the 8 MB the specification asks for' : '');

if ($missing !== [] || $strays !== []) {
    fwrite(STDERR, "\nThe package is wrong.\n");
    foreach ($missing as $entry) {
        fwrite(STDERR, "  missing: {$entry}\n");
    }
    foreach (array_slice($strays, 0, 10) as $entry) {
        fwrite(STDERR, "  should not be here: {$entry}\n");
    }
    exit(1);
}

echo "\nTo install it: unpack, put the boxlet/ directory where the site lives, point the\n"
    . "domain at boxlet/public, and open /install.php in a browser. No shell, no Composer.\n";

/** Runs a command, or stops with what it said. */
function run(string $command): string
{
    exec($command, $output, $status);
    if ($status !== 0) {
        fail("Failed: {$command}\n" . implode("\n", $output));
    }

    return trim(implode("\n", $output));
}

function removeTree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $file) {
        /** @var SplFileInfo $file */
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
