<?php

use App\Support\Url;

/**
 * One of the Statistics screen's tables in full (PLAN.md D-051). Provided by
 * AdminView::render().
 *
 * @var string $title
 * @var string $period
 * @var array{from: string, to: string, prevFrom: string, prevTo: string} $range
 * @var array{visitors: int, views: int, perVisitor: float, mobile: float} $totals
 * @var string $dimension
 * @var list<array{value: string, visitors: int, views: int}> $rows
 */
$all = $dimension;
?>
        <div class="page-header">
            <h1><?= e(t('stats.table.' . $dimension)) ?></h1>
        </div>
        <p class="page-subtitle"><a href="<?= e(Url::admin('statistics') . '?' . http_build_query(['period' => $period])) ?>"><?= e(t('stats.back')) ?></a></p>
<?php require __DIR__ . '/periods.php'; ?>

        <section class="panel stats-dimension" aria-label="<?= e(t('stats.table.' . $dimension)) ?>">
<?php require __DIR__ . '/table.php'; ?>
        </section>
