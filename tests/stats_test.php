<?php

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Modules\Stats\Agent;
use App\Modules\Stats\Bots;
use App\Modules\Stats\Tracker;

// Visit statistics (PLAN.md D-051, SPEC §5.7). adminSite() and adminPost() come from
// pages_admin_test.php.

const STATS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
const STATS_IP = '198.51.100.73';

/**
 * One request put through the tracker as public/index.php does after sending the page.
 *
 * @param array<string, string> $headers lower-case names
 */
function statsView(
    Db $db,
    string $path = '/about',
    array $headers = [],
    string $at = '2026-09-19 10:00:00',
    string $ip = STATS_IP,
    ?Response $response = null,
    string $method = 'GET',
): bool {
    $headers += ['user-agent' => STATS_CHROME, 'host' => 'example.test'];

    return Tracker::record(
        $db,
        new Request($method, $path, '', [], [], $headers, $ip),
        $response ?? Response::html('<p>A page</p>'),
        new DateTimeImmutable($at, new DateTimeZone('UTC')),
    );
}

function statsSite(string $driver): Db
{
    $db = adminSite($driver);
    Settings::set($db, 'timezone', 'Europe/Zagreb');

    return $db;
}

/** @return array{views: int, visitors: int} */
function statsTotals(Db $db, string $day = '2026-09-19'): array
{
    $row = $db->one('SELECT SUM(views) AS views, SUM(visitors) AS visitors FROM stats_views WHERE day = ?', [$day]);

    return ['views' => (int) ($row['views'] ?? 0), 'visitors' => (int) ($row['visitors'] ?? 0)];
}

function statsPageVisitors(Db $db, string $path, string $day = '2026-09-19'): int
{
    return (int) ($db->one('SELECT visitors FROM stats_page_visitors WHERE day = ? AND path = ?', [$day, $path])['visitors'] ?? 0);
}

test('the User-Agent parser names the family of real browsers, devices and systems', function () {
    $cases = [
        [STATS_CHROME, 'desktop', 'Chrome', 'Windows'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'desktop', 'Safari', 'macOS'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'mobile', 'Safari', 'iOS'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', 'mobile', 'Chrome', 'iOS'],
        ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', 'tablet', 'Safari', 'iOS'],
        ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.88 Mobile Safari/537.36', 'mobile', 'Chrome', 'Android'],
        ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36', 'tablet', 'Chrome', 'Android'],
        ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'mobile', 'Samsung Internet', 'Android'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', 'desktop', 'Edge', 'Windows'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', 'desktop', 'Opera', 'Windows'],
        ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'desktop', 'Firefox', 'Linux'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:130.0) Gecko/20100101 Firefox/130.0', 'desktop', 'Firefox', 'macOS'],
        ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'ChromeOS'],
        ['Something nobody has heard of/1.0', 'desktop', '', ''],
    ];
    foreach ($cases as [$ua, $device, $browser, $os]) {
        assertEquals(['device' => $device, 'browser' => $browser, 'os' => $os], Agent::parse($ua), $ua);
    }
});

test('crawlers, previews, tools and an empty User-Agent are programs; browsers are people', function () {
    $programs = [
        '',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'WhatsApp/2.23.20.0',
        'curl/8.5.0',
        'python-requests/2.31.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0.0.0 Safari/537.36',
        'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
        'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
        'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
    ];
    foreach ($programs as $ua) {
        assertTrue(Bots::is($ua), 'counted as a person: ' . $ua);
    }
    $people = [
        STATS_CHROME,
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 341.0.0.33.98',
    ];
    foreach ($people as $ua) {
        assertTrue(!Bots::is($ua), 'counted as a program: ' . $ua);
    }
});

testBothDrivers('a visitor is counted once a day, each view is counted, and each page knows its own visitors', function (string $driver) {
    $db = statsSite($driver);

    assertTrue(statsView($db, '/about'), 'the first view was not counted');
    statsView($db, '/about');
    statsView($db, '/hr/kontakt');
    statsView($db, '/about', ['user-agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0']);

    assertEquals(['views' => 4, 'visitors' => 2], statsTotals($db), 'totals');
    assertEquals(2, statsPageVisitors($db, '/about'), 'visitors of /about');
    assertEquals(1, statsPageVisitors($db, '/hr/kontakt'), 'visitors of a second page');
    $row = $db->one('SELECT device, browser, os, source, country FROM stats_views WHERE path = ? AND browser = ?', ['/about', 'Firefox']);
    assertEquals(['device' => 'desktop', 'browser' => 'Firefox', 'os' => 'Linux', 'source' => '', 'country' => ''], $row, 'what a row keeps');
});

testBothDrivers('the day is the site\'s own: a visit at 23:30 UTC is tomorrow in Zagreb', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', [], '2026-09-19 23:30:00');

    assertEquals(1, statsTotals($db, '2026-09-20')['views'], 'the view in Zagreb\'s day');
});

testBothDrivers('only a GET answered 200 with a public HTML page is a view', function (string $driver) {
    $db = statsSite($driver);

    assertTrue(!statsView($db, '/about', [], method: 'POST'), 'a POST');
    assertTrue(!statsView($db, '/nothing', [], response: Response::html('Not found', 404)), 'a 404');
    assertTrue(!statsView($db, '/about', [], response: Response::redirect('/over-there')), 'a redirect');
    assertTrue(!statsView($db, '/about', [], response: new Response('{}', 200, ['Content-Type' => 'application/json'])), 'not HTML');
    foreach (['/admin', '/admin/pages', '/form/3', '/sitemap'] as $path) {
        assertTrue(!statsView($db, $path), $path);
    }
    assertEquals(0, statsTotals($db)['views'], 'something was written');
});

testBothDrivers('prefetches, programs and the logged-in admin are not counted', function (string $driver) {
    $db = statsSite($driver);

    assertTrue(!statsView($db, '/about', ['sec-purpose' => 'prefetch']), 'a prefetch');
    assertTrue(!statsView($db, '/about', ['purpose' => 'prefetch']), 'an older prefetch');
    assertTrue(!statsView($db, '/about', ['user-agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']), 'a crawler');
    assertTrue(!statsView($db, '/about', ['user-agent' => '']), 'no User-Agent');
    assertTrue(!statsView($db, '/about', ['cookie' => 'theme=dark; boxlet_session=abc123']), 'the admin');
    assertTrue(statsView($db, '/about', ['cookie' => 'not_boxlet_session=abc123']), 'a cookie that only ends like it');
    assertEquals(1, statsTotals($db)['views'], 'views');
});

testBothDrivers('Do Not Track and Global Privacy Control are honoured by default, and can be ignored', function (string $driver) {
    $db = statsSite($driver);

    assertTrue(!statsView($db, '/about', ['dnt' => '1']), 'DNT');
    assertTrue(!statsView($db, '/about', ['sec-gpc' => '1']), 'GPC');
    Settings::set($db, 'stats_dnt', false);
    assertTrue(statsView($db, '/about', ['dnt' => '1']), 'DNT while the site does not honour it');
});

testBothDrivers('switched off, nothing is counted, and what was counted is kept', function (string $driver) {
    $db = statsSite($driver);
    statsView($db);
    Settings::set($db, 'stats_enabled', false);

    assertTrue(!statsView($db), 'counted while off');
    assertEquals(1, statsTotals($db)['views'], 'the earlier count');
});

testBothDrivers('the source is a bare domain; a link from the site itself, or none, is direct', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', ['referer' => 'https://www.google.com/search?q=boxlet+owner%40example.com']);
    statsView($db, '/about', ['referer' => 'https://example.test/hr/kontakt?x=1']);
    statsView($db, '/about', ['referer' => 'https://www.example.test/']);
    statsView($db, '/about', ['host' => 'example.test:8080']);

    $sources = array_map(static fn (array $row): string => (string) $row['source'], $db->all('SELECT DISTINCT source FROM stats_views ORDER BY source'));
    assertEquals(['', 'google.com'], $sources, 'sources');
    // An Android app sends its package name as the host: kept, since it says where the
    // visitor came from as well as a domain would.
    assertEquals('com.slack', Tracker::source('android-app://com.slack/', 'example.test'), 'an Android app');
    assertEquals('', Tracker::source('not an address', 'example.test'), 'a Referer that is not an address');
});

testBothDrivers('a path longer than the column is cut, not refused', function (string $driver) {
    $db = statsSite($driver);
    $long = '/' . str_repeat('a', 300);
    statsView($db, $long);

    assertEquals(255, strlen((string) ($db->one('SELECT path FROM stats_views')['path'] ?? '')), 'a long path, cut');
});

testBothDrivers('a new day makes a new salt, forgets yesterday\'s visitors, and counts them again', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', [], '2026-09-19 10:00:00');
    $first = Settings::get($db, 'stats_salt');

    statsView($db, '/about', [], '2026-09-20 10:00:00');
    $second = Settings::get($db, 'stats_salt');
    assertTrue(is_array($first) && is_array($second) && $first['salt'] !== $second['salt'], 'the salt was kept');
    assertEquals('2026-09-20', is_array($second) ? $second['day'] : null, 'the salt\'s day');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM stats_seen WHERE day <> ?', ['2026-09-20'])['n'] ?? -1), 'yesterday\'s keys');
    assertEquals(1, statsTotals($db, '2026-09-20')['visitors'], 'the same visitor, a new day');
    assertEquals(1, statsTotals($db, '2026-09-19')['visitors'], 'yesterday\'s count');
});

testBothDrivers('counts older than the retention period go at the first view of a day', function (string $driver) {
    $db = statsSite($driver);
    Settings::set($db, 'stats_retention', 6);
    statsView($db, '/about', [], '2026-03-01 10:00:00');
    statsView($db, '/about', [], '2026-03-25 10:00:00');

    statsView($db, '/about', [], '2026-09-19 10:00:00');
    $days = array_map(static fn (array $row): string => (string) $row['day'], $db->all('SELECT DISTINCT day FROM stats_page_visitors ORDER BY day'));
    assertEquals(['2026-03-25', '2026-09-19'], $days, 'days kept');
    assertEquals(2, (int) ($db->one('SELECT COUNT(DISTINCT day) AS n FROM stats_views')['n'] ?? 0), 'days kept in stats_views');
});

testBothDrivers('no visitor\'s address is stored anywhere', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', ['referer' => 'https://news.example.org/a']);

    $stored = json_encode([
        $db->all('SELECT * FROM stats_views'),
        $db->all('SELECT * FROM stats_page_visitors'),
        $db->all('SELECT * FROM stats_seen'),
        $db->all('SELECT * FROM settings'),
    ], JSON_THROW_ON_ERROR);
    assertTrue(!str_contains($stored, STATS_IP), 'the address');
    assertTrue(!str_contains($stored, bin2hex(STATS_IP)), 'the address in hex');
    assertTrue(!str_contains($stored, 'Chrome/128'), 'the whole User-Agent');
});

testBothDrivers('a public page sets no cookie, and the admin\'s own page is never a view', function (string $driver) {
    $db = statsSite($driver);
    createPage($db, 'en', 'about', 'About');
    $_SESSION = [];

    $page = dispatch('/about');
    assertEquals(200, $page->status, 'the page');
    assertTrue(!isset($page->headers['Set-Cookie']), 'a cookie on a public page');
    assertTrue(Tracker::wanted(new Request('GET', '/about', '', [], [], ['user-agent' => STATS_CHROME]), $page), 'the page is not a view');

    $admin = statsSite($driver);
    $dashboard = dispatch('/admin');
    assertEquals(200, $dashboard->status, 'the dashboard');
    assertTrue(!statsView($admin, '/admin', [], response: $dashboard), 'the dashboard was counted');
});

testBothDrivers('the Settings panel switches it off and on, and deletes every count', function (string $driver) {
    $db = statsSite($driver);
    statsView($db);
    assertContains(e(t('stats.on_now')), dispatch('/admin/settings')->body, 'on by default');

    assertRedirectedTo('/admin/settings#statistics', adminPost('/admin/settings/statistics', ['stats_retention' => '12']));
    assertEquals(['enabled' => false, 'dnt' => false, 'retention' => 12], Tracker::settings($db), 'saved');
    assertContains(e(t('stats.off_now')), dispatch('/admin/settings')->body, 'said to be off');

    adminPost('/admin/settings/statistics', ['stats_enabled' => '1', 'stats_dnt' => '1', 'stats_retention' => '99']);
    assertEquals(['enabled' => true, 'dnt' => true, 'retention' => 12], Tracker::settings($db), 'a retention not offered');

    adminPost('/admin/settings/statistics/erase', []);
    assertEquals(0, statsTotals($db)['views'], 'counts after erase');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM stats_seen')['n'] ?? -1), 'keys after erase');
    assertEquals(null, Settings::get($db, 'stats_salt'), 'the salt after erase');
});
