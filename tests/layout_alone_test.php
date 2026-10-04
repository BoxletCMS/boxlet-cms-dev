<?php

use App\Core\BlockDefinition;

/*
 * ONE LEFT ALONE, SAID (PLAN.md D-189, the owner). A layout of a fixed count across keeps the
 * items it is given (D-186): four numbers in Three across are three and one. The block says how
 * many stand side by side (`across`), and the inspector says so under Layout. adminSite() is
 * pages_admin_test.php's; builderBand() and builderRequest() builder_api_test.php's.
 */

testBothDrivers('the inspector says when the items leave one alone in the last row, and only then', function (string $driver) {
    $db = adminSite($driver);
    $stat = ['value' => '1', 'label' => 'one'];
    $id = createPage($db, 'en', 'rows', 'Rows', true, [['type' => 'stats', 'layout' => 'three', 'content' => ['items' => array_fill(0, 4, $stat)]]]);
    [, $section, [$block]] = builderBand($id);
    $inspect = static fn (array $b): string => json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'block', 'key' => $b['key'], 'section' => $section, 'blocks' => [$b], 'number' => 1])->body, true)['html'] ?? fail('no inspector');
    $with = static fn (string $layout, int $n): array => ['layout' => $layout, 'content' => ['heading' => '', 'items' => array_fill(0, $n, $stat)]] + $block;

    assertContains(e(t('block.stats.alone', ['count' => '4', 'across' => '3'])), $inspect($with('three', 4)), 'four in three across');
    assertContains('4 numbers in 3 across leave one alone in the last row.', $inspect($with('three', 4)), 'in the owner\'s words');
    assertContains(e(t('block.stats.alone', ['count' => '7', 'across' => '3'])), $inspect($with('three', 7)), 'seven');
    foreach ([['three', 3], ['three', 6], ['three', 5], ['four', 4], ['four', 6], ['three', 1]] as [$layout, $n]) {
        assertTrue(!str_contains($inspect($with($layout, $n)), 'data-layout-alone'), "{$n} in {$layout}: none alone");
    }
    assertContains('data-layout-alone', $inspect($with('four', 5)), 'five in four across');
});

test('a Gallery says it too, and `across` is checked like the rest of a block', function () {
    $definition = blockRegistry()->get('gallery');
    assertEquals(['two' => 2, 'three' => 3, 'four' => 4], $definition['across'], 'the gallery\'s');
    assertEquals([], blockRegistry()->get('logos')['across'], 'a Logos grid takes as many across as leave none alone (D-188)');
    $raw = require dirname(__DIR__) . '/app/Blocks/stats/block.php';
    foreach ([['wide' => 3], ['three' => 1], ['three' => '3']] as $bad) {
        $threw = false;
        try {
            BlockDefinition::validate('stats', ['across' => $bad] + $raw);
        } catch (RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "'across'");
        }
        assertTrue($threw, 'refused: ' . json_encode($bad));
    }
});
