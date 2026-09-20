<?php

namespace App\Modules\Stats;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Support\ClientIp;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Counts a page view on the site's own server (PLAN.md D-051, SPEC §5.7): no cookie, no
 * script on the page, no third party, and nothing stored that names a visitor.
 *
 * Called from public/index.php after the page has been sent, so a visitor never waits for
 * it and never sees it fail.
 *
 * A visitor is a key made fresh each day: HMAC-SHA256 over the address, the User-Agent and
 * the host, under a salt kept in settings and replaced by the first request of each new
 * day. The address is read for that one hash and never stored. The same request deletes
 * the previous day's keys, and with them any way of linking a visitor across two days, and
 * the counts older than the retention period: there is no cron to do it (D-051).
 */
final class Tracker
{
    /** Months of counts kept, as the Settings panel offers them. */
    public const RETENTION = [6, 12, 24, 36];

    private const RETENTION_DEFAULT = 24;

    /** First path segments that are never a visitor's page. */
    private const SKIPPED = ['admin', 'form', 'sitemap', 'install', 'install.php', '_boxlet'];

    /**
     * The module's settings, with their defaults: on, honouring DNT and GPC, two years,
     * and the country as the only thing said about where a visitor is.
     *
     * @return array{enabled: bool, dnt: bool, retention: int, missing: bool, group: bool, location: string}
     */
    public static function settings(Db $db): array
    {
        $stored = Settings::many($db, ['stats_enabled', 'stats_dnt', 'stats_retention', 'stats_missing', 'stats_group', 'stats_location']);
        $retention = $stored['stats_retention'];
        $location = $stored['stats_location'];

        return [
            'enabled' => $stored['stats_enabled'] !== false,
            'dnt' => $stored['stats_dnt'] !== false,
            'retention' => is_int($retention) && in_array($retention, self::RETENTION, true) ? $retention : self::RETENTION_DEFAULT,
            // Addresses that are not there: off unless asked for (O-20). A small site's 404s
            // are mostly other people's broken links and bots guessing at addresses.
            'missing' => $stored['stats_missing'] === true,
            // Rows of one or two visitors gathered into "Other" (O-20): on a small site a
            // table is otherwise a list of ones, and one visitor from one country on one
            // page is close to naming somebody.
            'group' => $stored['stats_group'] === true,
            // How much of where a visitor is (D-055). The country unless asked otherwise:
            // the city is the point at which a count starts to be about a person.
            'location' => is_string($location) && in_array($location, Place::LEVELS, true) ? $location : 'country',
        ];
    }

    /**
     * Whether this request and response could be a view to count, from what they carry
     * alone: no database, so that the admin's own requests and every asset, redirect and
     * error cost nothing. What needs the settings is decided in record().
     */
    public static function wanted(Request $request, Response $response): bool
    {
        $segment = strtolower(explode('/', ltrim($request->path, '/'))[0]);
        $purpose = strtolower(($request->header('sec-purpose') ?? '') . ' ' . ($request->header('purpose') ?? '') . ' ' . ($request->header('x-moz') ?? ''));

        return $request->method === 'GET'
            // A 404 is counted too, in its own table, where the owner asks for it (O-20);
            // record() decides which of the two it is.
            && ($response->status === 200 || $response->status === 404)
            && str_contains(strtolower((string) ($response->headers['Content-Type'] ?? '')), 'text/html')
            && !in_array($segment, self::SKIPPED, true)
            && !str_contains($purpose, 'prefetch')
            && !str_contains($purpose, 'prerender')
            // The logged-in admin, told by the session cookie without starting a session:
            // a visitor never receives one, since nothing on the public site starts one.
            && preg_match('~(?:^|;)\s*boxlet_session=~', $request->header('cookie') ?? '') !== 1
            && !Bots::is($request->header('user-agent') ?? '');
    }

    /**
     * Counts the view if it is one to count. Returns whether it did. $storagePath is where
     * the country database is looked for (Geo); '' counts every country as unknown.
     */
    public static function record(Db $db, Request $request, Response $response, ?DateTimeImmutable $now = null, string $storagePath = ''): bool
    {
        if (!self::wanted($request, $response)) {
            return false;
        }
        $settings = self::settings($db);
        if (!$settings['enabled']
            || ($settings['dnt'] && ($request->header('dnt') === '1' || $request->header('sec-gpc') === '1'))) {
            return false;
        }

        $zone = new DateTimeZone(Dates::zone($db));
        $today = ($now ?? new DateTimeImmutable())->setTimezone($zone);
        $day = $today->format('Y-m-d');
        $userAgent = $request->header('user-agent') ?? '';
        $host = self::host($request->header('host') ?? '');
        $path = mb_substr($request->path, 0, 255);
        // The visitor's own address where the site sits behind a proxy (O-20); the server's
        // own report of it otherwise. Read for the day's key and the country, never stored.
        $ip = ClientIp::of($request, Settings::text($db, 'trusted_proxies'));

        // An address that is not there is not a page view: it goes in its own table, and
        // only while the owner wants it counted (O-20). Before the salt and the visitor's
        // key, which a 404 has no use for — and bots asking for addresses that do not exist
        // are most of what lands here.
        if ($response->status === 404) {
            if ($settings['missing']) {
                self::increase(
                    $db,
                    'stats_missing',
                    ['day' => $day, 'path' => $path, 'source' => self::source($request->header('referer') ?? '', $host)],
                    'views = views + 1',
                    ['views' => 1],
                );
            }

            return $settings['missing'];
        }

        // Where the visitor is, as far as the owner asked (D-055). The address itself is
        // gone by the end of this method; what stays is a country, and at most a city.
        $place = $storagePath === '' ? new Place() : Geo::place($storagePath, $ip, $settings['location']);

        $salt = self::salt($db, $day, $today, $settings['retention']);
        $visitor = bin2hex(substr(hash_hmac('sha256', $ip . "\n" . $userAgent . "\n" . $host, $salt, true), 0, 16));
        $agent = Agent::parse($userAgent);

        $newToSite = self::firstSeen($db, $day, $visitor, '');
        if (self::firstSeen($db, $day, $visitor, $path)) {
            self::increase($db, 'stats_page_visitors', ['day' => $day, 'path' => $path], 'visitors = visitors + 1', ['visitors' => 1]);
        }
        self::increase(
            $db,
            'stats_views',
            [
                'day' => $day,
                'path' => $path,
                'source' => self::source($request->header('referer') ?? '', $host),
                'country' => $place->country,
                'device' => $agent['device'],
                'browser' => $agent['browser'],
                'os' => $agent['os'],
            ],
            'views = views + 1' . ($newToSite ? ', visitors = visitors + 1' : ''),
            ['views' => 1, 'visitors' => $newToSite ? 1 : 0],
        );

        // And where they were, in a table of its own (D-055), which the path never enters.
        // Written whenever anything at all is known, so the country level fills it too and
        // turning the level up later adds detail to the days after it rather than rewriting
        // the days before.
        if ($place->isKnown()) {
            self::increase(
                $db,
                'stats_places',
                ['day' => $day, 'country' => $place->country, 'region' => $place->region, 'city' => $place->city],
                'views = views + 1' . ($newToSite ? ', visitors = visitors + 1' : ''),
                [
                    'views' => 1,
                    'visitors' => $newToSite ? 1 : 0,
                    'latitude' => $place->latitude,
                    'longitude' => $place->longitude,
                ],
            );
        }

        return true;
    }

    /**
     * Every count and key gone, for the owner who asks from Settings. The salt goes too:
     * the next view starts a new day's keys.
     */
    public static function erase(Db $db): void
    {
        foreach (['stats_views', 'stats_page_visitors', 'stats_seen', 'stats_missing', 'stats_places'] as $table) {
            $db->query("DELETE FROM {$table}");
        }
        $db->query('DELETE FROM settings WHERE `key` = ?', ['stats_salt']);
    }

    /**
     * Where the visitor came from, as a bare domain; '' for a direct visit, which is also
     * what a link from the site itself is. Never the address that linked here: a query
     * string can carry anything, a search or an e-mail address included.
     */
    public static function source(string $referer, string $ownHost): string
    {
        $host = self::host((string) parse_url($referer, PHP_URL_HOST));

        return $host === $ownHost ? '' : mb_substr($host, 0, 190);
    }

    /** A host name as compared and stored: lower case, no port, no leading www. */
    private static function host(string $host): string
    {
        $host = strtolower(trim(explode(':', $host)[0], '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Today's salt. The first request of a new day makes one, and deletes the earlier
     * days' visitor keys and the counts older than the retention period.
     *
     * Two first requests at once can both make one; whichever the settings table keeps is
     * read back and used, so at most the loser's own view is keyed with a salt nobody
     * keeps — one visitor, once, possibly counted twice.
     */
    private static function salt(Db $db, string $day, DateTimeImmutable $today, int $retention): string
    {
        $stored = Settings::get($db, 'stats_salt');
        if (is_array($stored) && ($stored['day'] ?? null) === $day && is_string($stored['salt'] ?? null)) {
            return $stored['salt'];
        }

        Settings::set($db, 'stats_salt', ['day' => $day, 'salt' => bin2hex(random_bytes(32))]);
        $db->query('DELETE FROM stats_seen WHERE day <> ?', [$day]);
        $oldest = $today->modify("-{$retention} months")->format('Y-m-d');
        $db->query('DELETE FROM stats_views WHERE day < ?', [$oldest]);
        $db->query('DELETE FROM stats_page_visitors WHERE day < ?', [$oldest]);
        $db->query('DELETE FROM stats_missing WHERE day < ?', [$oldest]);
        $db->query('DELETE FROM stats_places WHERE day < ?', [$oldest]);

        $kept = Settings::get($db, 'stats_salt');

        return is_array($kept) && is_string($kept['salt'] ?? null) ? $kept['salt'] : '';
    }

    /** Marks the visitor seen today on $path ('' for the site); true the first time. */
    private static function firstSeen(Db $db, string $day, string $visitor, string $path): bool
    {
        try {
            $db->query('INSERT INTO stats_seen (day, hash, path) VALUES (?, ?, ?)', [$day, $visitor, $path]);

            return true;
        } catch (PDOException $e) {
            if (self::duplicate($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Adds to a row's counts, making the row if there is none (SPEC §5.0 has no upsert
     * that both databases accept). A row made at the same moment by another view is found
     * by the second UPDATE. Public because importing a file of counts (StatsExport) adds to
     * exactly the same rows in exactly the same way.
     *
     * @param array<string, string> $key
     * @param array<string, int|float|null> $first what a new row carries besides its key:
     *        its counts, and for a place its coordinates, which are set once and never
     *        added to
     */
    public static function increase(Db $db, string $table, array $key, string $increment, array $first): void
    {
        $where = implode(' AND ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($key)));
        $update = "UPDATE {$table} SET {$increment} WHERE {$where}";
        if ($db->query($update, array_values($key))->rowCount() > 0) {
            return;
        }

        $row = $key + $first;
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        try {
            $db->query("INSERT INTO {$table} ({$columns}) VALUES ({$marks})", array_values($row));
        } catch (PDOException $e) {
            if (!self::duplicate($e)) {
                throw $e;
            }
            $db->query($update, array_values($key));
        }
    }

    /** A unique constraint refused the row: SQLSTATE 23000 on MySQL and SQLite alike. */
    private static function duplicate(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
