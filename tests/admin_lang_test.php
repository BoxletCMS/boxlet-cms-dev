<?php

use App\Support\AdminLang;

// THE ADMIN IN THE SITE'S LANGUAGE (PLAN.md D-240, the owner): the main language chosen when
// the site was installed, where Boxlet has the admin in it; English everywhere else. A language
// that has the admin has all of it, so each one is checked against English, word for word.

/**
 * A language's admin words by file, as file name => what it returns.
 *
 * @return array<string, mixed>
 */
function adminLangFiles(string $code): array
{
    $files = [];
    foreach (glob(dirname(__DIR__) . '/lang/' . $code . '/*.php') ?: [] as $file) {
        $contents = require $file;
        $files[basename($file)] = is_array($contents) ? $contents : [];
    }

    return $files;
}

/** @return list<string> the :names a string fills */
function adminLangPlaceholders(string $text): array
{
    preg_match_all('~:([a-z][a-z_]*)~', $text, $names);
    $found = array_values(array_unique($names[1]));
    sort($found);

    return $found;
}

/** @return list<string> every language beside English whose folder carries the admin */
function adminLanguages(): array
{
    $codes = [];
    foreach (glob(dirname(__DIR__) . '/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $code = basename($dir);
        if ($code !== 'en' && AdminLang::has($code)) {
            $codes[] = $code;
        }
    }

    return $codes;
}

test('Croatian carries the admin', function () {
    assertTrue(in_array('hr', adminLanguages(), true), 'lang/hr has no admin');
    assertTrue(!AdminLang::has('de'), 'German has only a visitor\'s words, so the admin stays English there');
});

test('a language with the admin has every file and every word English has, and no stray', function () {
    $english = adminLangFiles('en');
    $all = array_merge(...array_values(array_filter($english, 'is_array')));
    foreach (adminLanguages() as $code) {
        $files = adminLangFiles($code);
        assertEquals(array_keys($english), array_keys($files), "lang/{$code} is split into the same files as English");
        $seen = [];
        foreach ($files as $name => $strings) {
            assertTrue(is_array($strings), "{$code}/{$name} does not return an array");
            $missing = array_keys(array_diff_key(is_array($english[$name]) ? $english[$name] : [], $strings));
            assertEquals([], $missing, "{$code}/{$name} lacks words English has");
            foreach ($strings as $key => $value) {
                assertTrue(is_string($value), "{$code}/{$name}: {$key} is not a string");
                assertTrue(!isset($seen[$key]), "{$code}: {$key} is in {$name} and in " . ($seen[$key] ?? ''));
                $seen[$key] = $name;
                if (array_key_exists($key, $all)) {
                    continue;
                }
                // Beside English's words, only the forms a count takes: `…_one` and `…_few` of a
                // word English has, in the same file.
                $stem = (string) preg_replace('~_(one|few)$~', '', (string) $key);
                $counted = $stem !== $key && (array_key_exists($stem, $english[$name]) || array_key_exists($stem . '_many', $english[$name]));
                assertTrue($counted, "{$code}/{$name}: {$key} is not a word English has, nor a counted form of one");
            }
        }
    }
});

test('a translation fills every placeholder English fills, and invents none', function () {
    $english = array_merge(...array_values(array_filter(adminLangFiles('en'), 'is_array')));
    foreach (adminLanguages() as $code) {
        foreach (array_filter(adminLangFiles($code), 'is_array') as $name => $strings) {
            foreach ($strings as $key => $value) {
                $key = (string) $key;
                $stem = (string) preg_replace('~_(one|few)$~', '', $key);
                $source = $english[$key] ?? $english[$stem] ?? $english[$stem . '_many'] ?? null;
                if (!is_string($source) || !is_string($value)) {
                    continue;
                }
                $want = adminLangPlaceholders($source);
                $have = adminLangPlaceholders($value);
                if (in_array($key, ['format.date', 'format.day'], true)) {
                    // A date's shape names any of what AdminLang::date() fills: English writes the
                    // month's name, Croatian its number.
                    assertEquals([], array_values(array_diff($have, ['day', 'm', 'month', 'year'])), "{$code}: {$key} names a placeholder nobody fills");
                    continue;
                }
                // A counted form may name :count where English wrote the 1 out ("1 section"), since
                // a Croatian "one" is also 21.
                $extra = array_values(array_diff($have, $want, $stem !== $key || str_ends_with($key, '_many') ? ['count'] : []));
                assertEquals([], array_values(array_diff($want, $have)), "{$code}/{$name}: {$key} drops a placeholder");
                assertEquals([], $extra, "{$code}/{$name}: {$key} names a placeholder nobody fills");
            }
        }
    }
});

test('a count takes the form its number takes', function () {
    foreach ([1 => 'one', 2 => 'other', 5 => 'other', 21 => 'other', 0 => 'other'] as $n => $form) {
        assertEquals($form, AdminLang::plural($n, 'en'), "English, {$n}");
    }
    $croatian = [1 => 'one', 2 => 'few', 4 => 'few', 5 => 'other', 11 => 'other', 12 => 'other', 14 => 'other',
        21 => 'one', 22 => 'few', 25 => 'other', 101 => 'one', 111 => 'other', 112 => 'other', 0 => 'other'];
    foreach ($croatian as $n => $form) {
        assertEquals($form, AdminLang::plural($n, 'hr'), "Croatian, {$n}");
    }
});

test('the admin speaks the language it is told, and English again after', function () {
    // No site: whatever the last test's site was, its main language is forgotten.
    \App\Support\Url::configure('', '');
    assertEquals('en', AdminLang::code(), 'no site, English');
    $said = AdminLang::speaking('hr', static fn (): array => [
        AdminLang::code(),
        tn('media.downloads_many', 1),
        tn('media.downloads_many', 3),
        tn('media.downloads_many', 5),
        tn('media.downloads_many', 21),
        AdminLang::number(1234.5, 1),
        AdminLang::date(new DateTimeImmutable('2026-09-18')),
        AdminLang::date(new DateTimeImmutable('2026-09-18'), false),
    ]);
    $hr = AdminLang::strings('hr');
    assertEquals([
        'hr',
        str_replace(':count', '1', $hr['media.downloads_one']),
        str_replace(':count', '3', $hr['media.downloads_few']),
        str_replace(':count', '5', $hr['media.downloads_many']),
        str_replace(':count', '21', $hr['media.downloads_one']),
        '1.234,5',
        '18. 9. 2026.',
        '18. 9.',
    ], $said, 'Croatian');
    assertEquals('en', AdminLang::code(), 'put back');
    assertEquals(['1 download', '3 downloads', '1,234.5', '18 Sep 2026'], [
        tn('media.downloads_many', 1), tn('media.downloads_many', 3), AdminLang::number(1234.5, 1), AdminLang::date(new DateTimeImmutable('2026-09-18')),
    ], 'English');
    assertEquals('en', AdminLang::speaking('de', static fn (): string => AdminLang::code()), 'a language without the admin is English');
});

testBothDrivers('a site whose main language is Croatian has its admin in Croatian', function (string $driver) {
    $db = installedSite(['hr' => 'Hrvatski', 'en' => 'English'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $hr = AdminLang::strings('hr');

    $login = dispatch('/admin/login')->body;
    assertContains('<html lang="hr"', $login, 'the login page');
    assertContains(e($hr['auth.submit']), $login, 'its button');

    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    $pages = dispatch('/admin/pages')->body;
    assertContains('<html lang="hr" data-decimal=","', $pages, 'the admin');
    assertContains(e($hr['pages.title']), $pages, 'the pages screen');
});

testBothDrivers('a site whose main language Boxlet has no admin for keeps it in English', function (string $driver) {
    $db = installedSite(['de' => 'Deutsch', 'hr' => 'Hrvatski'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);

    $pages = dispatch('/admin/pages')->body;
    assertContains('<html lang="en" data-decimal="."', $pages, 'German has no admin: English');
    assertContains(e(AdminLang::strings('en')['pages.title']), $pages, 'in English words');
});

test('a placeholder is filled whole, never inside a longer one, and a value is not read again (D-295)', function () {
    // The library said "Page 1 of 1s · 58 in all": :page was filled inside :pages.
    assertEquals('Page 1 of 2 · 58 in all', t('media.page_of', ['page' => '1', 'pages' => '2', 'total' => '58']), 'the pager');
    assertEquals('Page 1 of 2 · 58 in all', t('media.page_of', ['pages' => '2', 'page' => '1', 'total' => '58']), 'in either order');
    assertContains(':total', t('media.page_of', ['page' => ':total', 'pages' => '2', 'total' => '58']), 'a value holding a name stays as it was given');
});
