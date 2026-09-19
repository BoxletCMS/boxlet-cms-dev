<?php

namespace App\Modules\Stats;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Support\Dates;
use App\Support\Url;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The Statistics screen (PLAN.md D-051): a period, four figures against the period before,
 * the trend, and six tables — or one of those tables in full, when the owner asks for all
 * of it. Everything is in the address (?period=, ?all=), so a view can be bookmarked and the
 * back button works; nothing on the screen needs a script.
 */
final class StatsController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        if (!Tracker::settings($db)['enabled']) {
            return Response::redirect(Url::admin('settings') . '#statistics');
        }

        $period = is_string($request->query['period'] ?? null) && isset(StatsQuery::PERIODS[$request->query['period']])
            ? $request->query['period'] : '7d';
        $all = is_string($request->query['all'] ?? null) && isset(StatsQuery::DIMENSIONS[$request->query['all']])
            ? $request->query['all'] : null;

        $today = new DateTimeImmutable('now', new DateTimeZone(Dates::zone($db)));
        $range = StatsQuery::range($period, $today);
        $query = new StatsQuery($db);
        $totals = $query->totals($range['from'], $range['to']);

        $data = [
            'title' => t('stats.title'),
            'nav' => 'statistics',
            'wide' => true,
            // The dashboard's figure classes (stat-label, stat-value) as well as its own.
            'styles' => ['admin-dashboard.css', 'admin-stats.css', 'admin-stats-chart.css'],
            'period' => $period,
            'range' => $range,
            'totals' => $totals,
            // DB-IP's licence asks for credit where its countries are shown (CC BY 4.0).
            'geo' => Geo::status((string) $this->container->get('config')->get('app.storage_path')) !== null,
        ];

        if ($all !== null) {
            return AdminView::render($this->container, __DIR__ . '/views', 'all', $data + [
                'dimension' => $all,
                'rows' => $query->top($all, $range['from'], $range['to'], null),
            ]);
        }

        // Today alone is one point, which is not a line: its chart is the week it ends.
        $chart = $period === 'today' ? StatsQuery::range('7d', $today) : $range;

        return AdminView::render($this->container, __DIR__ . '/views', 'statistics', $data + [
            'previous' => $query->totals($range['prevFrom'], $range['prevTo']),
            'series' => $query->series($chart['from'], $chart['to'], $period === '12m'),
            'chartWeek' => $period === 'today',
            'tables' => array_map(
                static fn (string $dimension): array => $query->top($dimension, $range['from'], $range['to']),
                array_combine(array_keys(StatsQuery::DIMENSIONS), array_keys(StatsQuery::DIMENSIONS)),
            ),
        ]);
    }
}
