<?php

namespace App\Modules\Stats;

use App\Core\Db;
use JsonException;

/**
 * Taking the counts out and putting them back (PLAN.md O-20).
 *
 * Two shapes, for two jobs:
 *   - a CSV of one table as the screen is showing it, for a spreadsheet;
 *   - a JSON file of everything, row for row with the three tables, for keeping a copy or
 *     moving a site. JSON rather than a ZIP of CSVs because PHP's zip extension is not on
 *     every shared host, and a copy that only some servers can write is not a copy.
 *
 * Importing ADDS: the same day and address is increased, never replaced. Two exports of the
 * same days imported twice would therefore count them twice — the screen says so where the
 * file is chosen, and the counts are the owner's to clear if they do it by accident.
 */
final class StatsExport
{
    /** The tables a whole export carries, and the columns that make a row unique in each. */
    private const TABLES = [
        'stats_views' => ['keys' => ['day', 'path', 'source', 'country', 'device', 'browser', 'os'], 'counts' => ['views', 'visitors']],
        'stats_page_visitors' => ['keys' => ['day', 'path'], 'counts' => ['visitors']],
        'stats_missing' => ['keys' => ['day', 'path', 'source'], 'counts' => ['views']],
        // Where visitors were (D-055). Its coordinates are not counts and are not added to;
        // a row that comes back without them keeps whatever the site already had.
        'stats_places' => ['keys' => ['day', 'country', 'region', 'city'], 'counts' => ['views', 'visitors']],
    ];

    /**
     * One table as the screen shows it, as CSV: the same rows, the same order, the same
     * narrowing — what is on the screen is what comes out.
     */
    public static function csv(Db $db, string $dimension, StatsFilter $filter, bool $group): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        // All four arguments: PHP 8.4 deprecates leaving the escape character to its default,
        // and an empty one is what writes plain CSV that every spreadsheet reads back.
        $line = static fn (array $values) => fputcsv($out, $values, ',', '"', '');

        if ($dimension === 'missing') {
            $line(['path', 'source', 'views']);
            foreach ((new StatsQuery($db))->missing($filter, null) ?? [] as $row) {
                $line([$row['path'], $row['source'], $row['views']]);
            }
        } else {
            $line([StatsView::filterKey($dimension), 'visitors', 'views']);
            foreach ((new StatsQuery($db))->top($dimension, $filter, null, $group) as $row) {
                $line([
                    $row['value'] === StatsQuery::OTHER ? t('stats.other_small', ['count' => (string) StatsQuery::SMALL]) : $row['value'],
                    $row['visitors'] ?? '',
                    $row['views'],
                ]);
            }
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Every count there is, as JSON: the three tables row for row, with the day range they
     * cover, so a file can be looked at before it is trusted.
     */
    public static function json(Db $db): string
    {
        $data = ['boxlet' => 'statistics', 'version' => 1, 'exported' => gmdate('Y-m-d H:i:s'), 'tables' => []];
        foreach (array_keys(self::TABLES) as $table) {
            $data['tables'][$table] = $db->all("SELECT * FROM {$table} ORDER BY day");
        }

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Puts a file made by json() back, adding its counts to what is there.
     *
     * @return array{rows: int, error: string} how many rows were taken, or why none were
     */
    public static function import(Db $db, string $file): array
    {
        $body = is_file($file) ? (string) file_get_contents($file) : '';
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['rows' => 0, 'error' => t('stats.import_not_json')];
        }
        if (!is_array($data) || ($data['boxlet'] ?? '') !== 'statistics' || !is_array($data['tables'] ?? null)) {
            return ['rows' => 0, 'error' => t('stats.import_not_ours')];
        }

        $rows = 0;
        foreach (self::TABLES as $table => $shape) {
            foreach (is_array($data['tables'][$table] ?? null) ? $data['tables'][$table] : [] as $row) {
                if (!is_array($row) || !self::complete($row, $shape)) {
                    continue;
                }
                $key = [];
                foreach ($shape['keys'] as $column) {
                    $key[$column] = mb_substr((string) $row[$column], 0, 255);
                }
                $counts = [];
                $increment = [];
                foreach ($shape['counts'] as $column) {
                    $counts[$column] = max(0, (int) $row[$column]);
                    $increment[] = "{$column} = {$column} + " . $counts[$column];
                }
                Tracker::increase($db, $table, $key, implode(', ', $increment), $counts);
                $rows++;
            }
        }

        return ['rows' => $rows, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{keys: list<string>, counts: list<string>} $shape
     */
    private static function complete(array $row, array $shape): bool
    {
        foreach ([...$shape['keys'], ...$shape['counts']] as $column) {
            if (!array_key_exists($column, $row) || !is_scalar($row[$column])) {
                return false;
            }
        }

        return preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $row['day']) === 1;
    }
}
