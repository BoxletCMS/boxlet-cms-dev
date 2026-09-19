<?php

namespace App\Modules\Stats;

use App\Core\Db;
use DateTimeImmutable;

/**
 * What the Statistics screen and the dashboard card read (PLAN.md D-051): totals for a
 * period and the one before it, a series for the chart, and the top rows of a dimension.
 *
 * Visitors over a period are the sum of each day's visitors. A visitor's key lives for one
 * day (SPEC §5.7), so someone who came on Monday and Tuesday is two; that is the price of
 * forgetting them, and the screen says so.
 */
final class StatsQuery
{
    /** The periods the screen offers, and how many days each covers. */
    public const PERIODS = ['today' => 1, '7d' => 7, '30d' => 30, '12m' => 365];

    /** The dimensions a table can show: its key, and the column it reads. */
    public const DIMENSIONS = [
        'pages' => 'path',
        'sources' => 'source',
        'countries' => 'country',
        'devices' => 'device',
        'browsers' => 'browser',
        'os' => 'os',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The first and last day of a period ending today, and of the period of the same
     * length just before it.
     *
     * @return array{from: string, to: string, prevFrom: string, prevTo: string}
     */
    public static function range(string $period, DateTimeImmutable $today): array
    {
        $days = self::PERIODS[$period] ?? 7;
        $from = $today->modify('-' . ($days - 1) . ' days');

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'prevFrom' => $from->modify("-{$days} days")->format('Y-m-d'),
            'prevTo' => $from->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * Visitors, views, views per visitor and the share of visitors on a phone.
     *
     * @return array{visitors: int, views: int, perVisitor: float, mobile: float}
     */
    public function totals(string $from, string $to): array
    {
        $row = $this->db->one(
            "SELECT SUM(visitors) AS visitors, SUM(views) AS views,
                    SUM(CASE WHEN device = 'mobile' THEN visitors ELSE 0 END) AS mobile
             FROM stats_views WHERE day >= ? AND day <= ?",
            [$from, $to],
        );
        $visitors = (int) ($row['visitors'] ?? 0);
        $views = (int) ($row['views'] ?? 0);

        return [
            'visitors' => $visitors,
            'views' => $views,
            'perVisitor' => $visitors > 0 ? $views / $visitors : 0.0,
            'mobile' => $visitors > 0 ? (int) ($row['mobile'] ?? 0) / $visitors : 0.0,
        ];
    }

    /**
     * Visitors and views for every day from $from to $to, days with none included, so the
     * chart's line does not skip a quiet day. $week sums them by week (Monday first), for a
     * year's chart that would otherwise be 365 points wide.
     *
     * @return list<array{day: string, visitors: int, views: int}>
     */
    public function series(string $from, string $to, bool $week = false): array
    {
        $found = [];
        foreach ($this->db->all(
            'SELECT day, SUM(visitors) AS visitors, SUM(views) AS views FROM stats_views
             WHERE day >= ? AND day <= ? GROUP BY day',
            [$from, $to],
        ) as $row) {
            $found[(string) $row['day']] = ['visitors' => (int) $row['visitors'], 'views' => (int) $row['views']];
        }

        $series = [];
        $day = new DateTimeImmutable($from);
        $last = new DateTimeImmutable($to);
        while ($day <= $last) {
            $key = $day->format('Y-m-d');
            $bucket = $week ? $day->modify('monday this week')->format('Y-m-d') : $key;
            $series[$bucket] ??= ['day' => $bucket, 'visitors' => 0, 'views' => 0];
            $series[$bucket]['visitors'] += $found[$key]['visitors'] ?? 0;
            $series[$bucket]['views'] += $found[$key]['views'] ?? 0;
            $day = $day->modify('+1 day');
        }

        return array_values($series);
    }

    /**
     * The rows of one dimension, most visitors first — for pages, most views, since a
     * page's visitors come from their own table (migration 0019) and a page is judged by
     * how often it is read.
     *
     * @return list<array{value: string, visitors: int, views: int}>
     */
    public function top(string $dimension, string $from, string $to, ?int $limit = 10): array
    {
        $column = self::DIMENSIONS[$dimension] ?? 'path';
        $cap = $limit === null ? '' : ' LIMIT ' . max(1, $limit);

        if ($column === 'path') {
            $rows = $this->db->all(
                "SELECT path AS value, SUM(views) AS total_views FROM stats_views
                 WHERE day >= ? AND day <= ? GROUP BY path ORDER BY total_views DESC, path{$cap}",
                [$from, $to],
            );
            $visitors = [];
            foreach ($this->db->all(
                'SELECT path, SUM(visitors) AS visitors FROM stats_page_visitors
                 WHERE day >= ? AND day <= ? GROUP BY path',
                [$from, $to],
            ) as $row) {
                $visitors[(string) $row['path']] = (int) $row['visitors'];
            }

            return array_values(array_map(static fn (array $row): array => [
                'value' => (string) $row['value'],
                'visitors' => $visitors[(string) $row['value']] ?? 0,
                'views' => (int) $row['total_views'],
            ], $rows));
        }

        return array_values(array_map(static fn (array $row): array => [
            'value' => (string) $row['value'],
            'visitors' => (int) $row['total_visitors'],
            'views' => (int) $row['total_views'],
        ], $this->db->all(
            // Aliases that are not column names, so ORDER BY cannot be read as the column
            // rather than its sum.
            "SELECT {$column} AS value, SUM(visitors) AS total_visitors, SUM(views) AS total_views FROM stats_views
             WHERE day >= ? AND day <= ? GROUP BY {$column} ORDER BY total_visitors DESC, total_views DESC, {$column}{$cap}",
            [$from, $to],
        )));
    }

    /**
     * The change from $before to $now as a fraction (0.25 for a quarter more), or null when
     * there was nothing before to compare with.
     */
    public static function change(float $now, float $before): ?float
    {
        return $before > 0 ? ($now - $before) / $before : null;
    }
}
