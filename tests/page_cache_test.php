<?php

use App\Support\PageCache;

// The page cache (PLAN.md D-053): which answers are kept, which are handed back, and what
// empties it. The last test runs public/index.php itself, twice, the second time with no
// database at all, which only a kept page can answer.

/**
 * A request as the web server describes it.
 *
 * @param array<string, string> $more
 * @return array<string, string>
 */
function visit(string $uri, string $method = 'GET', array $more = []): array
{
    return $more + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'example.test'];
}

/**
 * What serve() sends for $server, or null when it answers nothing.
 *
 * @param array<string, string> $server
 */
function served(array $server, string $storage): ?string
{
    ob_start();
    $sent = PageCache::serve($server, $storage);
    $body = (string) ob_get_clean();

    return $sent ? $body : null;
}

/** A fresh cache folder and a storage folder with maintenance off. @return string storage */
function freshCache(): string
{
    removeTree(tmpPath('page-cache'));
    mkdir(tmpPath('page-cache/storage'), 0700, true);
    PageCache::use(tmpPath('page-cache/cache'));

    return tmpPath('page-cache/storage');
}

$on = static fn (): bool => true;

test('a visitor\'s page is kept and handed back to the next visitor', function () use ($on) {
    $storage = freshCache();
    assertEquals(null, served(visit('/about'), $storage), 'before it was kept');
    PageCache::after(visit('/about'), $storage, 200, 'text/html; charset=utf-8', '<p>About</p>', $on);
    assertEquals('<p>About</p>', served(visit('/about'), $storage), 'kept');
    assertEquals(null, served(visit('/about', 'GET', ['HTTP_HOST' => 'other.test']), $storage), 'another host');
    assertEquals(null, served(visit('/about', 'GET', ['HTTPS' => 'on']), $storage), 'https, kept apart from http');
    assertEquals(1, PageCache::count(), 'count');
});

test('what is never kept', function () use ($on) {
    $storage = freshCache();
    $cases = [
        'a query string' => [visit('/about?x=1'), 200, 'text/html'],
        'a POST' => [visit('/about', 'POST'), 200, 'text/html'],
        'the admin\'s own visit' => [visit('/about', 'GET', ['HTTP_COOKIE' => 'boxlet_session=abc']), 200, 'text/html'],
        'the admin' => [visit('/admin/pages'), 200, 'text/html'],
        'a form\'s answer' => [visit('/form/1'), 200, 'text/html'],
        'a download' => [visit('/download/3/x'), 200, 'text/html'],
        'a 404' => [visit('/nope'), 404, 'text/html'],
        'not HTML' => [visit('/feed'), 200, 'application/json'],
    ];
    foreach ($cases as $what => [$server, $status, $type]) {
        PageCache::after($server, $storage, $status, $type, 'body', static fn (): bool => true);
        assertEquals(0, PageCache::count(), $what);
    }
    PageCache::after(visit('/about'), $storage, 200, 'text/html', 'body', static fn (): bool => false);
    assertEquals(0, PageCache::count(), 'the owner switched it off');
    file_put_contents($storage . '/maintenance.flag', 'manual');
    PageCache::after(visit('/about'), $storage, 200, 'text/html', 'body', $on);
    assertEquals(0, PageCache::count(), 'during maintenance');
});

test('a kept page is not handed to the admin, nor during maintenance, nor after a day', function () use ($on) {
    $storage = freshCache();
    PageCache::after(visit('/about'), $storage, 200, 'text/html', 'kept', $on);
    assertEquals(null, served(visit('/about', 'GET', ['HTTP_COOKIE' => 'boxlet_session=abc']), $storage), 'the admin');
    assertEquals(null, served(visit('/about?preview=1'), $storage), 'with a query string');
    file_put_contents($storage . '/maintenance.flag', 'manual');
    assertEquals(null, served(visit('/about'), $storage), 'during maintenance');
    unlink($storage . '/maintenance.flag');
    foreach (glob(tmpPath('page-cache/cache/pages') . '/*.html') ?: [] as $file) {
        touch($file, time() - PageCache::MAX_AGE - 1);
    }
    assertEquals(null, served(visit('/about'), $storage), 'a day old');
});

test('any POST to the admin empties every kept page; a visitor\'s form and logging in do not', function () use ($on) {
    $storage = freshCache();
    PageCache::after(visit('/about'), $storage, 200, 'text/html', 'a', $on);
    PageCache::after(visit('/services'), $storage, 200, 'text/html', 'b', $on);
    PageCache::after(visit('/form/1', 'POST'), $storage, 302, 'text/html', '', $on);
    assertEquals(2, PageCache::count(), 'after a visitor sent a form');
    PageCache::after(visit('/admin/login', 'POST'), $storage, 302, 'text/html', '', $on);
    PageCache::after(visit('/admin/logout', 'POST'), $storage, 302, 'text/html', '', $on);
    assertEquals(2, PageCache::count(), 'after logging in and out, which change nothing');
    PageCache::after(visit('/admin/media/remake/step', 'POST'), $storage, 302, 'text/html', '', $on);
    assertEquals(0, PageCache::count(), 'after a change in the admin');
});

test('public/index.php keeps a page, then answers it again with no database at all', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    createPage($db, 'en', 'about', 'About us');
    $cache = (string) TestSite::$env['CACHE_PATH'];
    removeTree($cache . '/pages');
    $server = TestSite::$env + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/about', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'example.test'];

    [$first, , $status] = runIndex($server);
    assertEquals(200, $status, 'the first visit');
    assertContains('About us', $first, 'the page');
    assertEquals(1, count(glob($cache . '/pages/*.html') ?: []), 'kept');

    // The database is gone: only a kept page can answer this.
    [$second, , $again] = runIndex(['DB_PATH' => tmpPath('no-such-database.sqlite')] + $server);
    assertEquals(200, $again, 'the second visit');
    assertEquals($first, $second, 'the same page');
    assertTrue(!is_file(tmpPath('no-such-database.sqlite')) || filesize(tmpPath('no-such-database.sqlite')) === 0, 'the database was opened to answer');
    removeTree($cache . '/pages');
});

test('a page answered from the cache is still counted in the statistics', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    createPage($db, 'en', 'about', 'About us');
    $cache = (string) TestSite::$env['CACHE_PATH'];
    removeTree($cache . '/pages');
    $server = TestSite::$env + [
        'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/about', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'example.test',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        'REMOTE_ADDR' => '203.0.113.7',
    ];
    runIndex($server);
    $views = static fn (): int => (int) ($db->one('SELECT COALESCE(SUM(views), 0) AS n FROM stats_views')['n'] ?? 0);
    assertEquals(1, $views(), 'the first visit, made fresh');
    assertEquals(1, count(glob($cache . '/pages/*.html') ?: []), 'kept');
    runIndex($server);
    assertEquals(2, $views(), 'the second, from the cache');
    removeTree($cache . '/pages');
});
