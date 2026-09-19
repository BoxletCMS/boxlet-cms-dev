<?php

use App\Modules\Stats\StatsQuery;
use App\Modules\Stats\StatsView;
use App\Support\Url;

/**
 * The period links and the days they cover. Required by statistics.php and all.php; $all
 * keeps a full table open while the period changes.
 *
 * @var string $period
 * @var array{from: string, to: string, prevFrom: string, prevTo: string} $range
 * @var string|null $all
 */
?>
        <nav class="stats-periods" aria-label="<?= e(t('stats.period')) ?>">
<?php foreach (array_keys(StatsQuery::PERIODS) as $choice): ?>
            <a href="<?= e(Url::admin('statistics') . '?' . http_build_query(['period' => $choice] + ($all !== null ? ['all' => $all] : []))) ?>"<?= $choice === $period ? ' aria-current="page"' : '' ?>><?= e(t('stats.period.' . $choice)) ?></a>
<?php endforeach; ?>
        </nav>
        <p class="page-subtitle"><?= e($range['from'] === $range['to']
            ? StatsView::day($range['from'], true)
            : StatsView::day($range['from'], true) . ' – ' . StatsView::day($range['to'], true)) ?></p>
