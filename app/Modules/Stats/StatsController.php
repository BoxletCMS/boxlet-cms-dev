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
 * The Statistics screen (PLAN.md D-051, O-20): a period or a chosen range, four figures
 * against the period before, the trend, and six tables — or one of those tables in full.
 * Clicking a row narrows the whole screen to it.
 *
 * Everything is in the address, so a view can be bookmarked, shared and stepped out of with
 * the browser's Back, and nothing on the screen needs a script (StatsFilter).
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

        $all = is_string($request->query['all'] ?? null) && isset(StatsQuery::DIMENSIONS[$request->query['all']])
            ? $request->query['all'] : null;

        $today = new DateTimeImmutable('now', new DateTimeZone(Dates::zone($db)));
        $filter = StatsFilter::fromQuery($request->query, $today);
        $query = new StatsQuery($db);
        $totals = $query->totals($filter);
        $before = $filter->previous();

        $data = [
            'title' => t('stats.title'),
            'nav' => 'statistics',
            'wide' => true,
            'styles' => ['admin-stats.css', 'admin-stats-chart.css'],
            'filter' => $filter,
            'totals' => $totals,
            // DB-IP's licence asks for credit where its countries are shown (CC BY 4.0).
            'geo' => Geo::status((string) $this->container->get('config')->get('app.storage_path')) !== null,
        ];

        if ($all !== null) {
            return AdminView::render($this->container, __DIR__ . '/views', 'all', $data + [
                'dimension' => $all,
                'rows' => $query->top($all, $filter, null),
            ]);
        }

        // One day alone is one point, which is not a line: its chart is the week it ends,
        // narrowed the same way. A range past three months is drawn by week.
        $chart = $filter->days() === 1
            ? StatsFilter::fromQuery($filter->asQuery(['period' => '7d'], ['from', 'to']), $today)
            : $filter;

        return AdminView::render($this->container, __DIR__ . '/views', 'statistics', $data + [
            'previous' => $query->totals($filter, $before['from'], $before['to']),
            'series' => $query->series($chart, $chart->days() > 90),
            'chartWeek' => $filter->days() === 1,
            'chartByWeek' => $chart->days() > 90,
            'tables' => array_map(
                static fn (string $dimension): array => $query->top($dimension, $filter),
                array_combine(array_keys(StatsQuery::DIMENSIONS), array_keys(StatsQuery::DIMENSIONS)),
            ),
        ]);
    }
}
