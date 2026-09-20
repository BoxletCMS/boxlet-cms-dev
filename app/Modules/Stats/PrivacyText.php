<?php

namespace App\Modules\Stats;

/**
 * The suggested privacy-policy text for visit statistics (PLAN.md D-051), in every language
 * that has one (lang/{code}/stats-privacy.php), written for this site's settings: the
 * retention period it names is the one set, the paragraph on location says as much as the
 * site actually counts, and the one on Do Not Track appears only when it is honoured.
 *
 * A suggestion to adapt, not legal advice, and the panel says so.
 */
final class PrivacyText
{
    /**
     * @param array{enabled: bool, dnt: bool, retention: int, location?: string} $settings
     * @return array<string, array{language: string, text: string}> by language code
     */
    public static function all(array $settings, bool $countries): array
    {
        $texts = [];
        foreach (glob(dirname(__DIR__, 3) . '/lang/*/stats-privacy.php') ?: [] as $file) {
            $strings = require $file;
            if (!is_array($strings)) {
                continue;
            }
            $say = static fn (string $key): string => is_string($strings[$key] ?? null) ? $strings[$key] : '';
            $paragraphs = [
                $say('privacy.heading'),
                $say('privacy.what'),
                $say('privacy.visitor'),
                // The paragraph about location says as much as the site actually counts
                // (D-055): a text claiming only the country while the city is being counted
                // would be the worst kind of wrong.
                $countries ? $say('privacy.' . match ($settings['location'] ?? 'country') {
                    'city' => 'city',
                    'region' => 'region',
                    default => 'country',
                }) : '',
                str_replace(':period', $say('privacy.period.' . $settings['retention']), $say('privacy.kept')),
                $settings['dnt'] ? $say('privacy.dnt') : '',
                $say('privacy.basis'),
            ];
            $texts[basename(dirname($file))] = [
                'language' => $say('privacy.language'),
                'text' => implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => $p !== '')),
            ];
        }
        // English first, as the one every site can fall back on; the rest by code.
        uksort($texts, static fn (string $a, string $b): int => [$a !== 'en', $a] <=> [$b !== 'en', $b]);

        return $texts;
    }
}
