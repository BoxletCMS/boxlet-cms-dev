<?php

use App\Modules\Stats\Chart;
use App\Modules\Stats\StatsQuery;
use App\Modules\Stats\StatsView;
use App\Support\Url;

/**
 * The Statistics screen (PLAN.md D-051). Provided by AdminView::render().
 *
 * @var string $title
 * @var \App\Modules\Stats\StatsFilter $filter what the screen is showing
 * @var array{visitors: int, views: int, perVisitor: float, mobile: float} $totals
 * @var array{visitors: int, views: int, perVisitor: float, mobile: float} $previous
 * @var list<array{day: string, visitors: int, views: int}> $series
 * @var bool $chartWeek whether the chart shows the week the period ends, not the period
 * @var bool $chartByWeek whether the chart's points are weeks rather than days
 * @var array<string, list<array{value: string, visitors: int, views: int}>> $tables
 * @var bool $geo whether countries come from DB-IP's database, which asks to be credited
 */
$all = null;
$figures = [
    ['visitors', number_format($totals['visitors']), StatsView::change(StatsQuery::change($totals['visitors'], $previous['visitors']))],
    ['views', number_format($totals['views']), StatsView::change(StatsQuery::change($totals['views'], $previous['views']))],
    ['per_visitor', number_format($totals['perVisitor'], 1), StatsView::change(StatsQuery::change($totals['perVisitor'], $previous['perVisitor']))],
    ['mobile', StatsView::percent($totals['mobile']), '<span class="stats-change">' . e(t('stats.before', ['value' => StatsView::percent($previous['mobile'])])) . '</span>'],
];
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
<?php require __DIR__ . '/periods.php'; ?>

        <ul class="stats-figures" role="list">
<?php foreach ($figures as [$key, $value, $change]): ?>
            <li class="stats-figure">
                <span class="metric-label"><?= e(t('stats.figure.' . $key)) ?></span>
                <span class="metric-value"><?= e($value) ?></span>
                <?= $change ?>
            </li>
<?php endforeach; ?>
        </ul>

        <section class="panel stats-trend" aria-labelledby="stats-trend-heading">
            <div class="stats-trend-head">
                <h2 id="stats-trend-heading"><?= e(t($chartWeek ? 'stats.trend_week' : ($chartByWeek ? 'stats.trend_weekly' : 'stats.trend'))) ?></h2>
                <p class="stats-legend" aria-hidden="true">
                    <span class="stats-key stats-key-visitors"><?= e(t('stats.visitors')) ?></span>
                    <span class="stats-key stats-key-views"><?= e(t('stats.views')) ?></span>
                </p>
            </div>
            <?= Chart::trend($series, static fn (string $day): string => StatsView::day($day)) ?>
            <details class="stats-numbers">
                <summary><?= e(t('stats.as_table')) ?></summary>
                <table class="stats-table">
                    <thead><tr>
                        <th scope="col"><?= e(t($chartByWeek ? 'stats.week_of' : 'stats.day')) ?></th>
                        <th scope="col" class="stats-num"><?= e(t('stats.visitors')) ?></th>
                        <th scope="col" class="stats-num"><?= e(t('stats.views')) ?></th>
                    </tr></thead>
                    <tbody>
<?php foreach ($series as $point): ?>
                        <tr><th scope="row"><?= e(StatsView::day($point['day'], true)) ?></th><td class="stats-num"><?= e(number_format($point['visitors'])) ?></td><td class="stats-num"><?= e(number_format($point['views'])) ?></td></tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </section>

        <div class="stats-tables">
<?php foreach ($tables as $dimension => $rows): ?>
            <section class="panel stats-dimension" aria-labelledby="stats-<?= e($dimension) ?>">
                <h2 id="stats-<?= e($dimension) ?>"><?= e(t('stats.table.' . $dimension)) ?></h2>
<?php require __DIR__ . '/table.php'; ?>
<?php if (count($rows) >= 10): ?>
                <p class="stats-more"><a href="<?= e(Url::admin('statistics') . '?' . http_build_query($filter->asQuery(['all' => $dimension]))) ?>"><?= e(t('stats.show_all')) ?></a></p>
<?php endif; ?>
            </section>
<?php endforeach; ?>
        </div>

        <p class="hint stats-note"><?= e(t('stats.note')) ?> <a href="<?= e(Url::admin('settings') . '#statistics') ?>"><?= e(t('stats.settings_link')) ?></a></p>
<?php if ($geo): ?>
        <p class="hint stats-note"><a href="https://db-ip.com" target="_blank" rel="noopener"><?= e(t('stats.attribution')) ?></a></p>
<?php endif; ?>
