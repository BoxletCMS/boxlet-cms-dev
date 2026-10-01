<?php

use App\Core\Db;
use App\Modules\Design\Composition;
use App\Modules\Design\Design;
use App\Modules\Design\Presets;
use App\Modules\Settings\ChromeLook;

/*
 * THE FIVE CHARACTERS, SNAPSHOT BEFORE THEY MOVE (PLAN.md D-152).
 *
 * The characters are leaving PHP constants (Presets::ALL, Presets::COMPOSITION,
 * ChromeLook::CHARACTER) for designs/core/*.json, and the one promise of that move is that
 * no site can tell: the same decisions in the same key order, the same composition, the
 * same header and footer, the same words, the same compiled stylesheet, byte for byte.
 *
 * tests/fixtures/design_parity.json was written ONCE, from this function, on the code before
 * the move (bb0f408) — never from the code after it. It is not regenerated to make this
 * pass: a difference here is the move changing a site, and the fix is in the move.
 *
 * WHAT A CHARACTER IS, MEASURED THROUGH WHAT READS IT. The raw composition and look, and the
 * words, are read through the registry when it exists and through the constants and lang
 * keys before — $through is the only thing here that knows about the move; everything else goes through the calls the rest of
 * Boxlet makes — Presets::get(), Composition::style()/section()/layout(), ChromeLook::resolve(),
 * Design::publish() — so it holds whatever sits behind them.
 */

/**
 * Everything a site can observe of the five characters, and of a name that is none of them.
 *
 * @return array<string, mixed>
 */
function designParity(Db $db): array
{
    $registry = blockRegistry();
    // The registry's reading when there is one, the constants' before it: the one place
    // here that knows about the move.
    $through = static function (string $method, string $id, Closure $before): mixed {
        $registry = ['App\Modules\Design\Characters', $method];

        return is_callable($registry) ? $registry($id) : $before();
    };
    $cache = tmpPath('parity-tokens');
    removeTree($cache);
    mkdir($cache, 0700, true);

    $out = ['names' => Presets::names(), 'default' => Presets::DEFAULT, 'characters' => []];
    foreach (['editorial', 'minimal', 'bold', 'soft', 'brutalist'] as $id) {
        $decisions = Presets::get($id);
        $styles = [];
        $layouts = [];
        foreach ($registry->types() as $type) {
            $styles[$type] = Composition::style($id, $type);
            $layouts[$type] = Composition::layout($registry, $id, $type);
        }
        $file = Design::publish($db, $decisions, $cache);

        $out['characters'][$id] = [
            'exists' => Presets::exists($id),
            'decisions' => $decisions,
            // Separately, so a change of ORDER alone names itself in the failure.
            'order' => array_keys($decisions),
            'composition' => $through('composition', $id, static fn () => constant(Presets::class . '::COMPOSITION')[$id]),
            'look' => $through('look', $id, static fn () => constant(ChromeLook::class . '::CHARACTER')[$id]),
            'divider_accent' => Presets::dividerAccent($id),
            'styles' => $styles,
            'section_empty' => Composition::section($id, []),
            'section_mixed' => Composition::section($id, ['hero', 'text']),
            'layouts' => $layouts,
            'resolved_look' => ChromeLook::resolve($db, [], $id),
            'label' => $through('label', $id, static fn () => t('design.preset.' . $id)),
            'hint' => $through('hint', $id, static fn () => t('design.preset.' . $id . '_hint')),
            'css_file' => $file,
            'css' => (string) file_get_contents($cache . '/' . $file),
        ];
    }

    // A name that is no character: every reader falls back the way it always has.
    $out['unknown'] = [
        'exists' => Presets::exists('no-such-character'),
        'decisions' => Presets::get('no-such-character'),
        'style' => Composition::style('no-such-character', 'hero'),
        'section' => Composition::section('no-such-character', ['hero', 'text']),
        'layout' => Composition::layout($registry, 'no-such-character', 'hero'),
        'divider_accent' => Presets::dividerAccent('no-such-character'),
        'resolved_look' => ChromeLook::resolve($db, [], 'no-such-character'),
    ];

    return $out;
}

test('the five characters are what they were before they moved out of PHP (D-152)', function () {
    $snapshot = json_decode((string) file_get_contents(__DIR__ . '/fixtures/design_parity.json'), true);
    assertTrue(is_array($snapshot), 'tests/fixtures/design_parity.json is missing or not JSON');
    $now = designParity(installedSite());

    assertEquals($snapshot['names'], $now['names'], 'the characters, in their order');
    assertEquals($snapshot['default'], $now['default'], 'the default character');
    foreach ($snapshot['characters'] as $id => $was) {
        foreach ($was as $what => $value) {
            assertEquals($value, $now['characters'][$id][$what] ?? null, "{$id}: {$what}");
        }
    }
    foreach ($snapshot['unknown'] as $what => $value) {
        assertEquals($value, $now['unknown'][$what] ?? null, "an unknown character: {$what}");
    }
});
