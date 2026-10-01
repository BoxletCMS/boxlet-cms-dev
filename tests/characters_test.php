<?php

use App\Modules\Backup\Backup;
use App\Modules\Backup\Restore;
use App\Modules\Design\Characters;
use App\Modules\Design\Composition;
use App\Modules\Design\DesignSet;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Update\Upgrade;

// The characters as files (PLAN.md D-152, D-155): the five in designs/core/, and the ones an
// owner drops into designs/custom/. Helpers from upgrade_test.php, backup_test.php and
// zip_writer_test.php; sampleSet() from design_set_test.php.

/** A file's text, or '' when it is not there: a missing file is a failed check, not an error. */
function textOf(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Characters reading custom files from a folder of the test's own, for the length of $body.
 *
 * @param array<string, string> $files file name => contents
 */
function withCustomDesigns(array $files, Closure $body): void
{
    $dir = tmpPath('custom-designs');
    removeTree($dir);
    mkdir($dir, 0700, true);
    foreach ($files as $name => $contents) {
        file_put_contents($dir . '/' . $name, $contents);
    }
    Characters::use($dir, static fn () => blockRegistry());
    try {
        $body($dir);
    } finally {
        Characters::use('', static fn () => blockRegistry());
        removeTree($dir);
    }
}

/**
 * A custom character as a file: sampleSet() with an id and a name of its own.
 *
 * @param array<string, mixed> $changes merged over sampleSet()
 */
function customFile(string $id, array $changes = []): string
{
    return (string) json_encode(array_replace_recursive(sampleSet(), ['id' => $id, 'name' => ['en' => ucfirst($id), 'hr' => ucfirst($id)]], $changes));
}

// The owner's review, point 3: core is not validated as it loads, because validating asks for
// the default character. So it is held here instead, every file, every value.
test('the five core files pass validation and the format\'s own reader, changing nothing', function () {
    foreach (Characters::CORE as $id) {
        $decisions = Presets::get($id);
        $validated = Tokens::validate($decisions);
        assertEquals([], $validated['errors'], "{$id}: validation errors");
        assertEquals($decisions, $validated['decisions'], "{$id}: validation changed a value or the order");

        $file = (string) file_get_contents(dirname(__DIR__) . '/designs/core/' . $id . '.json');
        $read = DesignSet::parse($file, blockRegistry());
        assertEquals([], $read['errors'], "{$id}: the reader refused it");
        assertEquals([], $read['warnings'], "{$id}: the reader warned");
        assertEquals($decisions, $read['set']['decisions'] ?? null, "{$id}: decisions as the reader has them");
        assertEquals(Characters::look($id), $read['set']['look'] ?? null, "{$id}: look");
        assertEquals(Characters::composition($id), $read['set']['composition'] ?? null, "{$id}: composition");
        // What export writes is the file, byte for byte: the core files are in canonical form.
        $set = $read['set'] ?? fail("{$id}: nothing was read");
        assertEquals($file, DesignSet::export($id, $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition'], $set['author']), "{$id}: not in canonical form");

        // A character Boxlet ships makes none of the owner's exceptions (D-063, D-066, D-076).
        $own = array_intersect_key((array) json_decode($file, true)['decisions'], Presets::neutral());
        assertEquals([], $own, "{$id} ships a decision that is the owner's to make");
        assertEquals('core', Characters::source($id), "{$id}: source");
    }
    assertTrue(Characters::exists(Presets::DEFAULT), 'the default character');
});

test('a custom file joins the characters after the five, and is used like one of them', function () {
    withCustomDesigns(['harbour.json' => customFile('harbour'), 'aaa-studio.json' => customFile('studio')], function () {
        assertEquals(array_merge(Characters::CORE, ['harbour', 'studio']), Presets::names(), 'core first, then custom by name');
        assertEquals('custom', Characters::source('harbour'), 'source');
        assertEquals('Harbour', Characters::label('harbour'), 'its own name');
        assertEquals('Navy and sand.', Characters::hint('harbour'), 'its own description');
        assertEquals('#1d3557', Presets::get('harbour')['seed'], 'its decisions');
        assertEquals(array_keys(Presets::get(Presets::DEFAULT)), array_keys(Presets::get('harbour')), 'in stored order');
        assertEquals('split', Composition::layout(blockRegistry(), 'harbour', 'hero'), 'its composition');
        assertEquals('bar', Characters::look('harbour')['nav_style'], 'its look');
        assertEquals([], Characters::skipped(), 'skipped');
    });
    assertTrue(!Characters::exists('harbour'), 'a custom character outlived its folder');
});

// D-153: an owner's set may hold an owner's exceptions, each in its place.
test('a custom character may carry colours by hand and nudges, kept in stored order', function () {
    $file = customFile('harbour', ['decisions' => ['color_link' => '#0b3d91', 'nudge_h1' => '4']]);
    withCustomDesigns(['harbour.json' => $file], function () {
        $decisions = Presets::get('harbour');
        assertEquals('#0b3d91', $decisions['color_link'], 'a colour by hand');
        assertEquals('4', $decisions['nudge_h1'], 'a nudge');
        assertEquals(array_keys(Presets::get(Presets::DEFAULT)), array_keys($decisions), 'the order');
    });
});

// The owner's review, point 7: a file that cannot be used is left out and named, never fatal.
test('a custom file that cannot be used is skipped, with the file and the reason', function () {
    $files = [
        'broken.json' => '{"format": "boxlet-design-set", ',
        'unreadable.json' => customFile('pale', ['decisions' => ['color_background' => '#ffffff', 'color_text' => '#fefefe']]),
        'design.json' => (static function (): string {
            $set = json_decode(customFile('just-a-design'), true);
            unset($set['composition']);

            return (string) json_encode($set);
        })(),
        'soft.json' => customFile('soft'),
        'good.json' => customFile('harbour'),
    ];
    withCustomDesigns($files, function () {
        $skipped = [];
        foreach (Characters::skipped() as $entry) {
            $skipped[$entry['file']] = $entry['reason'];
        }
        assertEquals(['broken.json', 'design.json', 'soft.json', 'unreadable.json'], array_keys($skipped), 'the files skipped');
        assertContains('not JSON', $skipped['broken.json'], 'bad JSON');
        assertContains('WCAG AA', $skipped['unreadable.json'], 'refused by validation');
        assertContains('not a character', $skipped['design.json'], 'no composition');
        assertContains('soft', $skipped['soft.json'], 'an id core has');

        assertEquals('core', Characters::source('soft'), 'core soft untouched');
        assertEquals(array_merge(Characters::CORE, ['harbour']), Presets::names(), 'the rest still read');
    });
});

// D-155 and the owner's review, point 2: an update never carries designs/custom/ off.
test('an update swaps designs/ child by child and leaves designs/custom/ where it is', function () {
    [$root, $db] = oldSite();
    mkdir($root . '/designs/core', 0700, true);
    mkdir($root . '/designs/custom', 0700, true);
    file_put_contents($root . '/designs/core/minimal.json', 'old core');
    file_put_contents($root . '/designs/custom/harbour.json', 'the owner\'s own');

    $storage = $root . '/storage';
    $upgrade = new Upgrade($db, $root, $storage, $root . '/public/cache', $root . '/public');
    mkdir($upgrade->dir(), 0770, true);
    $package = $upgrade->dir() . '/package.zip';
    newPackage($package, 'v0.2.0');
    // The package again, with designs/ in it, as a release carries it.
    $zip = new ZipArchive();
    $zip->open($package) === true || fail('cannot reopen the package');
    $zip->addFromString('boxlet/designs/core/minimal.json', 'new core');
    $zip->addFromString('boxlet/designs/.htaccess', 'Require all denied');
    $zip->close();

    $upgrade->start('v0.2.0', 'v0.1.0', '2026-10-01-120000-update');
    $steps = 0;
    do {
        $result = $upgrade->step(microtime(true));
        $steps++;
    } while (!$result['done'] && $steps < 200);

    assertTrue($result['done'], 'never done');
    assertEquals('new core', (string) file_get_contents($root . '/designs/core/minimal.json'), 'core is the release\'s');
    assertEquals('the owner\'s own', textOf($root . '/designs/custom/harbour.json'), 'custom/ after the update');

    $upgrade->rollBack();
    assertEquals('old core', (string) file_get_contents($root . '/designs/core/minimal.json'), 'core after rolling back');
    assertEquals('the owner\'s own', textOf($root . '/designs/custom/harbour.json'), 'custom/ after rolling back');
    removeTree($root);
});

// The owner's review, point 2: backup and restore carry designs/custom/ too, and a restore
// writes its files back without taking away one the owner added since.
test('a backup carries designs/custom/, and a restore puts its files back without emptying it', function () {
    [$db, , $dir] = backupSite('sqlite');
    mkdir($dir . '/designs-custom', 0700, true);
    file_put_contents($dir . '/designs-custom/harbour.json', 'harbour');
    $backup = new Backup($db, $dir, $dir . '/backups', $dir . '/uploads', $dir . '/m', $dir . '/.env', 'test-key', $dir . '/designs-custom');
    $name = $backup->start('manual');
    stepToEnd(static fn () => $backup->step(null));
    assertEquals('harbour', zipContents($dir . '/backups/' . $name . '.zip')['designs/custom/harbour.json'] ?? null, 'the custom file in the archive');

    unlink($dir . '/designs-custom/harbour.json');
    file_put_contents($dir . '/designs-custom/later.json', 'added after the backup');
    foreach (['cache', 'public', 'storage'] as $folder) {
        is_dir($dir . '/' . $folder) || mkdir($dir . '/' . $folder, 0700, true);
    }
    $restore = new Restore($db, dirname(__DIR__), $dir . '/backups', $dir . '/uploads', $dir . '/m', $dir . '/.env', 'test-key', $dir . '/storage', $dir . '/cache', $dir . '/public', $dir . '/designs-custom');
    $restore->start($name);
    stepToEnd(static fn () => $restore->step(null));

    assertEquals('harbour', textOf($dir . '/designs-custom/harbour.json'), 'the custom file after restoring');
    assertEquals('added after the backup', textOf($dir . '/designs-custom/later.json'), 'an owner\'s file was taken away');
    removeTree($dir);
});
