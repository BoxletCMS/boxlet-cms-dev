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

/**
 * A filter as the address would give it, on 19 September 2026.
 *
 * @param array<string, string> $query
 */
function statsFilter(array $query): App\Modules\Stats\StatsFilter
{
    return App\Modules\Stats\StatsFilter::fromQuery($query, new DateTimeImmutable('2026-09-19'));
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

testBothDrivers('totals, the change on the period before, and the share on a phone come from the counts', function (string $driver) {
    $db = statsSite($driver);
    $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    // The week before: one visitor, two views.
    statsView($db, '/about', [], '2026-09-10 10:00:00');
    statsView($db, '/about', [], '2026-09-10 11:00:00');
    // This week: three visitors (one of them twice), one on a phone, six views.
    statsView($db, '/about', [], '2026-09-18 10:00:00');
    statsView($db, '/about', [], '2026-09-19 10:00:00');
    statsView($db, '/hr/kontakt', [], '2026-09-19 10:05:00');
    statsView($db, '/about', ['user-agent' => $phone], '2026-09-19 12:00:00');
    statsView($db, '/about', ['user-agent' => $phone], '2026-09-19 12:01:00');
    statsView($db, '/about', ['referer' => 'https://news.example.org/'], '2026-09-19 13:00:00', '192.0.2.44');

    $week = statsFilter(['period' => '7d']);
    assertEquals(['from' => '2026-09-13', 'to' => '2026-09-19'], ['from' => $week->from, 'to' => $week->to], 'the range');
    assertEquals(['from' => '2026-09-06', 'to' => '2026-09-12'], $week->previous(), 'the week before it');
    $query = new App\Modules\Stats\StatsQuery($db);
    $now = $query->totals($week);
    $before = $query->totals($week, ...array_values($week->previous()));
    // 18 Sep and 19 Sep are two days, so the same person is two visitors (SPEC §5.7).
    assertEquals(['visitors' => 4, 'views' => 6, 'perVisitor' => 1.5, 'mobile' => 0.25], $now, 'this week');
    assertEquals(3.0, App\Modules\Stats\StatsQuery::change($now['visitors'], $before['visitors']), 'one visitor to four: three times more');
    assertEquals(null, App\Modules\Stats\StatsQuery::change(5, 0), 'nothing to compare with');

    $series = $query->series($week);
    assertEquals(7, count($series), 'a point for every day, quiet ones too');
    assertEquals(['day' => '2026-09-19', 'visitors' => 3, 'views' => 5], $series[6], 'the last day');
    $weeks = $query->series(statsFilter(['period' => 'custom', 'from' => '2026-09-07', 'to' => '2026-09-19']), true);
    assertEquals(['2026-09-07', '2026-09-14'], array_column($weeks, 'day'), 'weeks start on Monday');
    assertEquals([2, 6], array_column($weeks, 'views'), 'each week\'s views');

    $pages = $query->top('pages', $week);
    assertEquals(['value' => '/about', 'visitors' => 4, 'views' => 5], $pages[0], 'the first page, with its own visitors');
    $sources = $query->top('sources', $week);
    assertEquals([['value' => '', 'visitors' => 3, 'views' => 5], ['value' => 'news.example.org', 'visitors' => 1, 'views' => 1]], $sources, 'sources');
    assertEquals(1, count($query->top('sources', $week, 1)), 'a limit');
});

test('the chart\'s gridlines are whole numbers above the highest value', function () {
    foreach ([0 => 1, 3 => 1, 4 => 2, 7 => 5, 16 => 10, 35 => 20, 61 => 50, 1234 => 500] as $max => $step) {
        assertEquals($step, App\Modules\Stats\Chart::step($max), "the step for {$max}");
    }
});

testBothDrivers('the Statistics screen shows the period\'s figures, its trend and its tables', function (string $driver) {
    $db = statsSite($driver);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zagreb')))->format('Y-m-d 10:00:00');
    statsView($db, '/about', ['referer' => 'https://news.example.org/'], $today);

    $screen = dispatch('/admin/statistics?period=30d');
    assertEquals(200, $screen->status, 'the screen');
    foreach (['stats-figure', 'stats-chart-lines', 'news.example.org', '/about', e(t('stats.device.desktop')), 'Chrome', 'Windows', e(t('stats.unknown'))] as $shown) {
        assertContains($shown, $screen->body, $shown);
    }
    assertContains('aria-current="page">' . e(t('stats.period.30d')), $screen->body, 'the chosen period');
    assertTrue(!str_contains($screen->body, 'style="'), 'a style attribute, which the admin CSP refuses');
    assertContains(e(t('admin.nav.statistics')), $screen->body, 'the bar\'s link');

    $all = dispatch('/admin/statistics?period=30d&all=sources');
    assertContains(e(t('stats.table.sources')), $all->body, 'one table in full');
    assertContains('news.example.org', $all->body, 'its rows');
    assertEquals(200, dispatch('/admin/statistics?period=nonsense&all=nonsense')->status, 'nonsense in the address');
});

testBothDrivers('switched off, the screen, the rail\'s link and the Overview\'s visitors are gone', function (string $driver) {
    $db = statsSite($driver);
    assertContains(e(t('overview.visitors')), dispatch('/admin')->body, 'visitors on the Overview while on');

    Settings::set($db, 'stats_enabled', false);
    assertRedirectedTo('/admin/settings#statistics', dispatch('/admin/statistics'));
    $dashboard = dispatch('/admin')->body;
    assertTrue(!str_contains($dashboard, e(t('overview.visitors'))), 'visitors on the Overview while off');
    assertTrue(!str_contains($dashboard, e(t('admin.nav.statistics'))), 'the bar\'s link while off');
});

/**
 * Writes a small MaxMind DB file, so the reader is tested against the format rather than
 * against itself: a tree built from $networks (CIDR => country code), records of
 * $recordSize bits, and the data and metadata sections as the specification lays them out.
 *
 * @param array<string, string> $networks
 */
function writeMmdb(string $path, array $networks, int $ipVersion = 6, int $recordSize = 24): void
{
    $string = static fn (string $s): string => chr((2 << 5) | strlen($s)) . $s;
    $uint = static function (int $type, int $value, int $bytes): string {
        $encoded = substr(pack('J', $value), 8 - $bytes);

        return $type <= 7 ? chr(($type << 5) | $bytes) . $encoded : chr($bytes) . chr($type - 7) . $encoded;
    };
    $map = static function (array $pairs) use ($string): string {
        $out = chr((7 << 5) | count($pairs));
        foreach ($pairs as $key => $value) {
            $out .= $string((string) $key) . $value;
        }

        return $out;
    };

    // The tree: each node is two children, an int (a node), ['data', offset], or null.
    $nodes = [[null, null]];
    $data = '';
    foreach ($networks as $cidr => $code) {
        [$address, $length] = explode('/', $cidr);
        $packed = (string) inet_pton($address);
        $length = (int) $length;
        if (strlen($packed) === 4 && $ipVersion === 6) {
            $packed = str_repeat("\0", 12) . $packed;
            $length += 96;
        }
        $offset = strlen($data);
        $data .= $map(['country' => $map(['iso_code' => $string($code)])]);
        $node = 0;
        for ($i = 0; $i < $length; $i++) {
            $bit = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
            if ($i === $length - 1) {
                $nodes[$node][$bit] = ['data', $offset];
                break;
            }
            if (!is_int($nodes[$node][$bit])) {
                $nodes[] = [null, null];
                $nodes[$node][$bit] = count($nodes) - 1;
            }
            $node = $nodes[$node][$bit];
        }
    }

    $count = count($nodes);
    $value = static fn ($child): int => $child === null ? $count : (is_int($child) ? $child : $count + 16 + $child[1]);
    $tree = '';
    foreach ($nodes as [$left, $right]) {
        [$l, $r] = [$value($left), $value($right)];
        $tree .= match ($recordSize) {
            24 => substr(pack('N', $l), 1) . substr(pack('N', $r), 1),
            28 => substr(pack('N', $l), 1) . chr((($l >> 24) << 4) | ($r >> 24)) . substr(pack('N', $r), 1),
            default => pack('N', $l) . pack('N', $r),
        };
    }

    $metadata = $map([
        'binary_format_major_version' => $uint(5, 2, 1),
        'binary_format_minor_version' => $uint(5, 0, 0),
        'build_epoch' => $uint(9, 1788220800, 4),
        'database_type' => $string('Test-Country'),
        'description' => $map([]),
        'ip_version' => $uint(5, $ipVersion, 1),
        'languages' => chr(0) . chr(11 - 7),
        'node_count' => $uint(6, $count, 4),
        'record_size' => $uint(5, $recordSize, 1),
    ]);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $tree . str_repeat("\0", 16) . $data . "\xAB\xCD\xEFMaxMind.com" . $metadata);
}

const STATS_NETWORKS = ['8.8.8.0/24' => 'US', '198.51.100.0/24' => 'HR', '2001:db8::/32' => 'DE'];

/** The test's storage directory, with no country database in it. */
function geoStorage(): string
{
    // A test outside testBothDrivers() has no site, and so no storage path of its own.
    $storage = (string) (TestSite::$env['STORAGE_PATH'] ?? '');
    $storage = $storage !== '' ? $storage : tmpPath('storage');
    removeTree($storage . '/geo');

    return $storage;
}

test('the MaxMind DB reader finds a country for IPv4 and IPv6, with every record size', function () {
    foreach ([24, 28, 32] as $size) {
        $path = tmpPath("geo-{$size}.mmdb");
        writeMmdb($path, STATS_NETWORKS, 6, $size);
        $reader = new App\Modules\Stats\Mmdb($path);
        $country = static fn (string $ip): mixed => ($reader->get($ip) ?? [])['country']['iso_code'] ?? null;

        assertEquals('US', $country('8.8.8.8'), "{$size}: 8.8.8.8");
        assertEquals('HR', $country('198.51.100.73'), "{$size}: an address in a /24");
        assertEquals('DE', $country('2001:db8:1::5'), "{$size}: IPv6");
        assertEquals(null, $reader->get('203.0.113.9'), "{$size}: an address the file does not know");
        assertEquals(null, $reader->get('not an address'), "{$size}: not an address");
        assertEquals('Test-Country', $reader->metadata()['database_type'] ?? null, "{$size}: metadata");
    }

    $v4 = tmpPath('geo-v4.mmdb');
    writeMmdb($v4, ['8.8.8.0/24' => 'US'], 4);
    assertEquals('US', ((new App\Modules\Stats\Mmdb($v4))->get('8.8.8.8') ?? [])['country']['iso_code'] ?? null, 'an IPv4 file');
    assertEquals(null, (new App\Modules\Stats\Mmdb($v4))->get('2001:db8::1'), 'IPv6 in an IPv4 file');

    file_put_contents(tmpPath('not.mmdb'), str_repeat('x', 1000));
    assertThrows(fn () => new App\Modules\Stats\Mmdb(tmpPath('not.mmdb')), 'no metadata');
});

testBothDrivers('with a country database, a view is counted under its country', function (string $driver) {
    $db = statsSite($driver);
    $storage = geoStorage();
    writeMmdb(tmpPath('geo.mmdb'), STATS_NETWORKS);
    App\Modules\Stats\Geo::install($storage, tmpPath('geo.mmdb'));

    Tracker::record($db, new Request('GET', '/about', '', [], [], ['user-agent' => STATS_CHROME, 'host' => 'example.test'], '198.51.100.73'), Response::html('x'), new DateTimeImmutable('2026-09-19 10:00:00'), $storage);
    Tracker::record($db, new Request('GET', '/about', '', [], [], ['user-agent' => STATS_CHROME, 'host' => 'example.test'], '203.0.113.9'), Response::html('x'), new DateTimeImmutable('2026-09-19 10:00:00'), $storage);
    $countries = array_map(static fn (array $row): string => (string) $row['country'], $db->all('SELECT country FROM stats_views ORDER BY country'));
    assertEquals(['', 'HR'], $countries, 'countries');
    removeTree($storage . '/geo');
});

test('a country database is installed gzipped or not, and a file that is not one never replaces it', function () {
    $storage = geoStorage();
    writeMmdb(tmpPath('geo.mmdb'), STATS_NETWORKS);
    file_put_contents(tmpPath('geo.mmdb.gz'), (string) gzencode((string) file_get_contents(tmpPath('geo.mmdb'))));

    App\Modules\Stats\Geo::install($storage, tmpPath('geo.mmdb.gz'));
    assertEquals('HR', App\Modules\Stats\Geo::country($storage, '198.51.100.73'), 'from the gzipped file');
    assertEquals(['built' => '2026-09-01', 'type' => 'Test-Country'], App\Modules\Stats\Geo::status($storage), 'the status');

    file_put_contents(tmpPath('junk.mmdb'), 'not a database');
    assertThrows(fn () => App\Modules\Stats\Geo::install($storage, tmpPath('junk.mmdb')), t('stats.geo_not_a_database'));
    writeMmdb(tmpPath('empty.mmdb'), ['203.0.113.0/24' => 'FR']);
    assertThrows(fn () => App\Modules\Stats\Geo::install($storage, tmpPath('empty.mmdb')), t('stats.geo_not_a_database'));
    assertEquals('HR', App\Modules\Stats\Geo::country($storage, '198.51.100.73'), 'the file in use after two refusals');
    assertEquals(['dbip-country-lite.mmdb'], array_values(array_diff((array) scandir($storage . '/geo'), ['.', '..'])), 'files left behind');
    removeTree($storage . '/geo');
});

test('downloading takes this month\'s file, or last month\'s when this one is not out yet', function () {
    $storage = geoStorage();
    writeMmdb(tmpPath('geo.mmdb'), STATS_NETWORKS);
    $gz = (string) gzencode((string) file_get_contents(tmpPath('geo.mmdb')));
    if (!is_dir(tmpPath('dbip'))) {
        mkdir(tmpPath('dbip'), 0700, true);
    }
    file_put_contents(tmpPath('dbip/lite-2026-08.mmdb.gz'), $gz);
    $source = 'file://' . tmpPath('dbip') . '/lite-%s.mmdb.gz';

    App\Modules\Stats\Geo::download($storage, new DateTimeImmutable('2026-09-01 00:10:00'), $source);
    assertEquals('US', App\Modules\Stats\Geo::country($storage, '8.8.8.8'), 'last month\'s file');

    removeTree($storage . '/geo');
    assertThrows(fn () => App\Modules\Stats\Geo::download($storage, new DateTimeImmutable('2026-12-05'), $source), 'could not be downloaded');
    assertEquals(null, App\Modules\Stats\Geo::status($storage), 'a database after a failed download');
});

testBothDrivers('the screen credits DB-IP only while its database is in use, and a refused upload is shown as a refusal', function (string $driver) {
    statsSite($driver);
    $storage = geoStorage();
    assertTrue(!str_contains(dispatch('/admin/statistics')->body, e(t('stats.attribution'))), 'credited with no database');
    assertContains(e(t('stats.geo_none')), dispatch('/admin/settings')->body, 'the panel without one');

    writeMmdb(tmpPath('geo.mmdb'), STATS_NETWORKS);
    App\Modules\Stats\Geo::install($storage, tmpPath('geo.mmdb'));
    assertContains(e(t('stats.attribution')), dispatch('/admin/statistics')->body, 'the credit');
    assertContains(e(t('stats.geo_in_use', ['date' => '2026-09-01'])), dispatch('/admin/settings')->body, 'the panel with one');

    assertRedirectedTo('/admin/settings#statistics', adminPost('/admin/settings/statistics/countries/upload', []));
    assertContains('notice notice-error" role="status">' . e(t('stats.geo_upload_none')), dispatch('/admin/settings')->body, 'the refusal, coloured as one');
    removeTree($storage . '/geo');
});

test('the privacy text is written for the settings, in English and Croatian', function () {
    $texts = App\Modules\Stats\PrivacyText::all(['enabled' => true, 'dnt' => true, 'retention' => 24], true);
    assertEquals(['en', 'hr'], array_slice(array_keys($texts), 0, 2), 'English first, then Croatian');
    assertContains('after 24 months', $texts['en']['text'], 'the retention in English');
    assertContains('nakon 24 mjeseca', $texts['hr']['text'], 'the retention in Croatian, declined');
    assertContains('DB-IP', $texts['en']['text'], 'the country paragraph');
    assertContains('Do Not Track', $texts['hr']['text'], 'the DNT paragraph');

    $plain = App\Modules\Stats\PrivacyText::all(['enabled' => true, 'dnt' => false, 'retention' => 6], false)['en']['text'];
    assertContains('after 6 months', $plain, 'another retention');
    assertTrue(!str_contains($plain, 'DB-IP') && !str_contains($plain, 'Do Not Track'), 'paragraphs that are not true of this site');
    foreach ($texts as $code => $text) {
        assertTrue(!str_contains($text['text'], ':period') && !str_contains($text['text'], 'privacy.'), "{$code}: a placeholder or key left in");
    }
});

testBothDrivers('the Settings panel offers the privacy text', function (string $driver) {
    statsSite($driver);
    geoStorage();
    $settings = dispatch('/admin/settings')->body;
    assertContains(e(t('stats.privacy_title')), $settings, 'the section');
    assertContains('lang="hr"', $settings, 'the Croatian version');
    assertContains(e('We count visits to this website on our own server'), $settings, 'the English text');
});

// Filtering by clicking, with the state in the address (PLAN.md O-20).

test('the address says what is shown, and an address that makes no sense still shows a screen', function () {
    $week = statsFilter(['period' => '7d']);
    assertEquals(['2026-09-13', '2026-09-19', 7], [$week->from, $week->to, $week->days()], 'seven days to today');

    $custom = statsFilter(['period' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-12']);
    assertEquals(['2026-09-10', '2026-09-12'], [$custom->from, $custom->to], 'a range of its own');
    assertEquals(['from' => '2026-09-07', 'to' => '2026-09-09'], $custom->previous(), 'the three days before it');
    assertEquals(['2026-09-10', '2026-09-12'], (function (App\Modules\Stats\StatsFilter $f): array {
        return [$f->from, $f->to];
    })(statsFilter(['period' => 'custom', 'from' => '2026-09-12', 'to' => '2026-09-10'])), 'a range given backwards');

    assertEquals('7d', statsFilter(['period' => 'nonsense'])->period, 'a period nobody offers');
    assertEquals('7d', statsFilter(['period' => 'custom', 'from' => 'yesterday'])->period, 'a date that is not one');

    $narrowed = statsFilter(['period' => '7d', 'country' => 'HR', 'source' => '-']);
    // In the dimensions' own order, whatever order the address gave them in.
    assertEquals(['source' => '', 'country' => 'HR'], $narrowed->narrowed, 'what it is narrowed to; - is "none"');
    assertEquals(['period' => '7d', 'source' => '-', 'country' => 'HR'], $narrowed->asQuery(), 'and back into an address');
    assertEquals(['period' => '30d', 'country' => 'HR'], $narrowed->asQuery(['period' => '30d'], ['source']), 'with one changed and one removed');
});

testBothDrivers('narrowing by a country changes every figure, and says what it cannot split', function (string $driver) {
    $db = statsSite($driver);
    $storage = geoStorage();
    writeMmdb(tmpPath('geo.mmdb'), STATS_NETWORKS);
    App\Modules\Stats\Geo::install($storage, tmpPath('geo.mmdb'));
    $at = '2026-09-19 10:00:00';
    $croatian = fn (string $path, string $ip) => Tracker::record($db, new Request('GET', $path, '', [], [], ['user-agent' => STATS_CHROME, 'host' => 'example.test'], $ip), Response::html('x'), new DateTimeImmutable($at), $storage);
    $croatian('/about', '198.51.100.73');
    $croatian('/about', '198.51.100.74');
    $croatian('/hr/kontakt', '8.8.8.8');

    $query = new App\Modules\Stats\StatsQuery($db);
    assertEquals(3, $query->totals(statsFilter(['period' => '7d']))['views'], 'every view');
    $onlyHr = statsFilter(['period' => '7d', 'country' => 'HR']);
    assertEquals(2, $query->totals($onlyHr)['views'], 'the Croatian ones');
    assertEquals([['value' => '/about', 'visitors' => null, 'views' => 2]], $query->top('pages', $onlyHr), 'pages, with visitors it cannot split');
    assertEquals(2, $query->top('pages', statsFilter(['period' => '7d']))[0]['visitors'], 'and with them when nothing else is narrowed');

    $screen = dispatch('/admin/statistics?period=7d&country=HR')->body;
    assertContains(e(t('stats.narrowed')), $screen, 'the screen says it is narrowed');
    assertContains(e(t('stats.clear_filters')), $screen, 'and how to stop');
    assertTrue(!str_contains($screen, '/hr/kontakt'), 'a page from another country');
    assertContains('country=HR&amp;browser=Chrome', $screen, 'a second narrowing keeps the first');
    removeTree($storage . '/geo');
});

testBothDrivers('a value in a table is a link that narrows the screen to it', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', ['referer' => 'https://news.example.org/']);

    $screen = dispatch('/admin/statistics?period=7d')->body;
    assertContains('?period=7d&amp;source=news.example.org', $screen, 'the source narrows');
    assertContains('?period=7d&amp;path=%2Fabout', $screen, 'the page narrows');
    assertContains('?period=7d&amp;browser=Chrome', $screen, 'the browser narrows');

    // The row of a dimension already narrowed is words, not a link to narrow to it again.
    // Other tables' rows still carry it, which is why this looks at the cell itself.
    $only = dispatch('/admin/statistics?period=7d&path=' . rawurlencode('/about'))->body;
    assertContains('<span>/about</span>', $only, 'the page named as words');
    assertTrue(!str_contains($only, 'title="' . e(t('stats.narrow_to', ['value' => '/about'])) . '"'), 'and offered to narrow to again');
});

testBothDrivers('a range of its own is shown, and kept while narrowing', function (string $driver) {
    $db = statsSite($driver);
    statsView($db, '/about', [], '2026-09-10 10:00:00');
    statsView($db, '/about', [], '2026-09-19 10:00:00');

    $screen = dispatch('/admin/statistics?period=custom&from=2026-09-09&to=2026-09-11')->body;
    assertContains('9 Sep 2026 – 11 Sep 2026', $screen, 'the days it shows');
    assertContains('value="2026-09-09"', $screen, 'the field holds the day chosen');
    assertContains('from=2026-09-09&amp;to=2026-09-11&amp;path=%2Fabout', $screen, 'narrowing keeps the range');
});
