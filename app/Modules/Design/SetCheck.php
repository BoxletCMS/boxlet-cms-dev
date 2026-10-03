<?php

namespace App\Modules\Design;

use App\Core\Blocks;

/**
 * A DESIGN SET CHECKED FOR ITS AUTHOR (PLAN.md D-183), what bin/check-set.php prints: what an
 * import says — the errors that refuse it and the warnings it shows — and more that an import
 * does not ask:
 *
 * - a number off its step, which an import puts on the nearest one without a word;
 * - the contrast pairs in the other mode, which an owner may switch to, and then Save refuses;
 * - the three closest pairs and the least veil over a picture, so a pass by a hair is seen;
 * - every place the set's own choices put words in a column under 16rem (SetMeasure).
 */
final class SetCheck
{
    /**
     * @return array{id: string, name: string, character: bool, errors: list<string>, warnings: list<string>, notes: list<string>}
     */
    public static function check(string $json, Blocks $registry): array
    {
        $result = DesignSet::parse($json, $registry);
        $out = ['id' => '', 'name' => '', 'character' => false, 'errors' => $result['errors'], 'warnings' => $result['warnings'], 'notes' => []];
        $set = $result['set'];
        if ($set === null) {
            return $out;
        }
        $out['id'] = $set['id'];
        $out['name'] = $set['name']['en'] ?? (string) reset($set['name']);
        $out['character'] = $set['composition'] !== null;

        // A number off its step is put on the nearest one, silently on import.
        $raw = json_decode($json, true);
        foreach (['decisions' => $set['decisions'], 'look' => $set['look'], 'composition.section' => $set['composition']['section'] ?? []] as $part => $kept) {
            $given = $part === 'composition.section' ? ($raw['composition']['section'] ?? []) : ($raw[$part] ?? []);
            foreach (is_array($given) ? $given : [] as $key => $value) {
                $now = $kept[$key] ?? null;
                if (is_numeric($value) && is_numeric($now) && (float) $value !== (float) $now) {
                    $out['warnings'][] = "{$part}.{$key}: {$value} is not on its step, and is kept as {$now}";
                }
            }
        }

        $decisions = $set['decisions'];
        $other = ($decisions['mode'] ?? '') === 'dark' ? 'light' : 'dark';
        foreach (Tokens::validate(['mode' => $other] + $decisions)['errors'] as $key => $message) {
            $out['warnings'][] = "in {$other} mode, {$key}: {$message}";
        }

        $resolved = Tokens::resolve($decisions);
        $colors = Palette::forDecisions($resolved);
        $pairs = PalettePairs::pairs($colors, $resolved['secondary'] !== '', Tokens::byHand($resolved), Tokens::ownChrome($resolved));
        usort($pairs, static fn (array $a, array $b): int => $a['ratio'] <=> $b['ratio']);
        foreach (array_slice($pairs, 0, 3) as $pair) {
            $out['notes'][] = sprintf('contrast %s: %.2f:1 (%s on %s)', $pair['pair'], $pair['ratio'], $pair['foreground'], $pair['background']);
        }
        $out['notes'][] = sprintf('veil over a picture: at least %.2f', PaletteInks::veil($colors));

        foreach (SetMeasure::narrow($resolved, $set['composition'], $set['patterns'], $registry) as $narrow) {
            $out['warnings'][] = '16rem: ' . $narrow;
        }

        return $out;
    }
}
