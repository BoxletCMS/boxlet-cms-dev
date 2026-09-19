<?php

// Admin strings (CLAUDE.md: every admin string goes through t() and lands in lang/).
//
// The strings passed the 300-line rule, so they are split by concern. t() loads every
// file in lang/{locale}/ rather than a list of names, which makes three things worth
// proving: that a file cannot be forgotten, that a key cannot be defined twice, and that
// nothing sits directly under lang/ where it would be loaded for every locale.
//
// The second matters because the loader merges with +, which keeps the LEFT-hand value.
// A key defined in two files would therefore resolve to whichever file glob() returned
// first — alphabetical, and silent. That is a bug nobody would see until a string changed
// in one file and did not change on screen.

/**
 * Every language file, as file name => whatever it returns.
 *
 * Deliberately NOT annotated as array<string, array<string, string>>. These files are
 * require'd at runtime and can return anything; declaring the shape would make the
 * assertions below check what the annotation already promised, which PHPStan reports as
 * a condition that cannot fail — a test that looks like coverage and is none.
 *
 * @return array<string, mixed>
 */
function langFiles(): array
{
    $files = [];
    foreach (glob(dirname(__DIR__) . '/lang/' . ADMIN_LANG . '/*.php') ?: [] as $file) {
        $contents = require $file;
        $files[basename($file)] = is_array($contents) ? $contents : [];
    }

    return $files;
}

test('every file in lang/ is loaded by t()', function () {
    $files = langFiles();
    assertTrue(count($files) > 1, 'lang/ holds only one file, so the split is gone');

    // One key from each file, resolved through t() itself: if a file were not loaded,
    // t() would return the key unchanged.
    foreach ($files as $name => $strings) {
        assertTrue($strings !== [], "{$name} defines no strings");
        $key = (string) array_key_first($strings);
        assertEquals($strings[$key], t($key), "{$name}: t() does not resolve {$key}, so the file is not loaded");
    }
});

test('no key is defined in two language files', function () {
    $seen = [];
    $duplicates = [];
    foreach (langFiles() as $name => $strings) {
        foreach (array_keys($strings) as $key) {
            if (isset($seen[$key])) {
                $duplicates[] = "{$key} in {$seen[$key]} and {$name}";
            }
            $seen[$key] = $name;
        }
    }

    assertEquals([], $duplicates, 'the same key is defined in two files, so one of them is ignored');
});

// Inside ONE file, PHP keeps the last of two equal keys without a word, so a string added
// near the top of a file was silently replaced by an older one lower down: "Upload" was
// shown as "Upload pictures" (D-052). Read from the source, since the array has already
// lost the first by the time anything can look at it.
test('no key is defined twice in one language file', function () {
    $duplicates = [];
    foreach (glob(dirname(__DIR__) . '/lang/*/*.php') ?: [] as $file) {
        preg_match_all("~^\\s*'([^']+)'\\s*=>~m", (string) file_get_contents($file), $keys);
        foreach (array_unique(array_diff_assoc($keys[1], array_unique($keys[1]))) as $key) {
            $duplicates[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $key;
        }
    }

    assertEquals([], $duplicates, 'a key is defined twice in one file, so the first is ignored');
});

test('every string is a string, and every placeholder is filled by someone', function () {
    foreach (langFiles() as $name => $strings) {
        assertTrue(is_array($strings), "{$name} does not return an array");
        foreach ($strings as $key => $value) {
            assertTrue(is_string($key) && $key !== '', "{$name} has a key that is not a string");
            assertTrue(is_string($value), "{$name}: {$key} is not a string");
        }
    }
});

test('a key with no string is returned as itself, not as an empty page', function () {
    // The failure mode this protects: a screen rendering blank where a sentence should be.
    // Returning the key is ugly on purpose — it is visible, and it names what is missing.
    assertEquals('nothing.defined.here', t('nothing.defined.here'), 'a missing key');
});

// Nesting is what keeps Slice 6 from merging Croatian into English. A file directly under
// lang/ would be loaded for whichever locale happened to be active — or for none, which is
// worse, because the strings would simply vanish from the screen.
test('no language file sits directly under lang/', function () {
    $stray = glob(dirname(__DIR__) . '/lang/*.php') ?: [];

    assertEquals([], array_map('basename', $stray), 'these belong in lang/' . ADMIN_LANG . '/');
});

test('the admin locale has a directory of its own', function () {
    $directory = dirname(__DIR__) . '/lang/' . ADMIN_LANG;

    assertTrue(is_dir($directory), 'lang/' . ADMIN_LANG . ' is missing');
    assertTrue(count(glob($directory . '/*.php') ?: []) > 1, 'the strings are not split by concern');
});
