<?php

use App\Modules\Stats\StatsView;
use App\Support\Url;

/**
 * One dimension as a table: its rows, each with a bar for its share, and the counts.
 * Required by statistics.php and all.php.
 *
 * The bar is an SVG whose rect is as long as the share: a width in a style attribute would
 * be refused by the admin's CSP, and an attribute on an SVG is not a style.
 *
 * @var string $dimension
 * @var list<array{value: string, visitors: int, views: int}> $rows
 * @var array{visitors: int, views: int, perVisitor: float, mobile: float} $totals
 */
$byViews = $dimension === 'pages';
$whole = max(1, $byViews ? $totals['views'] : $totals['visitors']);
?>
<?php if ($rows === []): ?>
                <p class="hint"><?= e(t('stats.none')) ?></p>
<?php else: ?>
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('stats.column.' . $dimension)) ?></th>
                            <th scope="col" class="stats-num"><?= e(t('stats.visitors')) ?></th>
                            <th scope="col" class="stats-num"><?= e(t('stats.views')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row):
    $share = min(100, ($byViews ? $row['views'] : $row['visitors']) * 100 / $whole);
    ?>
                        <tr>
                            <th scope="row" class="stats-name">
                                <svg class="stats-bar" viewBox="0 0 100 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect width="<?= e(number_format($share, 1, '.', '')) ?>" height="1"/></svg>
<?php if ($byViews): ?>
                                <a href="<?= e(Url::asset(implode('/', array_map('rawurlencode', explode('/', ltrim($row['value'], '/')))))) ?>" target="_blank" rel="noopener"><?= e($row['value']) ?></a>
<?php else: ?>
                                <span><?= e(StatsView::label($dimension, $row['value'])) ?></span>
<?php endif; ?>
                            </th>
                            <td class="stats-num"><?= e(number_format($row['visitors'])) ?></td>
                            <td class="stats-num"><?= e(number_format($row['views'])) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
<?php endif; ?>
