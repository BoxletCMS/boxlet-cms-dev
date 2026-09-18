<?php

// Every field says what it does (PLAN.md D-038). The owner asked for it everywhere; this
// holds it for the fields a block declares, which is where a new one is most easily added
// without a word of explanation: a block field with no hint.block.* entry fails here.

test('every block field has a description', function () {
    $missing = [];
    foreach (['app/Blocks'] as $dir) {
        $registry = App\Core\Blocks::discover(dirname(__DIR__) . '/' . $dir);
        foreach ($registry->types() as $type) {
            foreach ($registry->get($type)['fields'] as $field => $spec) {
                $keys = ['hint.block.' . $type . '.' . $field];
                // A repeater's own fields are fields too, one segment down (SPEC §5.3).
                foreach (array_keys($spec['fields'] ?? []) as $itemField) {
                    $keys[] = 'hint.block.' . $type . '.' . $field . '.' . $itemField;
                }
                foreach ($keys as $key) {
                    if (t($key) === $key) {
                        $missing[] = $key;
                    }
                }
            }
        }
    }
    assertEquals([], $missing, 'block fields with no description in lang/en/hints.php');
});

test('a hint that is not written draws nothing, and one that is draws itself', function () {
    assertEquals('', field_hint('hint.nothing.here'), 'a missing hint drew its key');
    assertContains('The large headline', field_hint('hint.block.hero.heading'), 'a written hint');
});
