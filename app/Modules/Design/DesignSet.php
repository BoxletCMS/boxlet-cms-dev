<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Modules\Settings\ChromeLook;

/**
 * A whole design as a portable file: the `boxlet-design-set` format, version 1 (PLAN.md
 * D-152).
 *
 * ONE VALIDATOR FOR EVERY WAY IN. A file an owner imports, one dropped in designs/custom/
 * over FTP, and — later — one a model writes all arrive here, and nothing reaches a site
 * that has not passed the checks the Appearance screen itself applies: Tokens::validate()
 * for the decisions, contrast included (D-154); ChromeLook's closed sets for the look;
 * SectionStyle's for the composition, and the block registry for layouts. A file can say
 * nothing those could not.
 *
 * NEVER IN A SET: menus, words, CSS, font or picture URLs, free markup. The decisions are
 * closed sets and bounded numbers, the fonts are Typography's pairings, and a composition
 * surface is never `image`: a picture is content, not design.
 *
 * REFUSED OR WARNED. Anything that would change what the design means is refused, with
 * the field and the reason. Anything that only cannot be carried over — a key this Boxlet
 * does not have, a block type this site does not have, a layout a block does not offer —
 * is left out with a warning, because a set may come from a site with more blocks than
 * this one.
 *
 * @phpstan-type SetComposition array{section: array<string, string>, surfaces: array<string, string>, dividers: array<string, string>, layouts: array<string, string>}
 * @phpstan-type ParsedSet array{id: string, name: array<string, string>, description: array<string, string>, author: string, tags: list<string>, decisions: array<string, string>, look: array<string, string>, composition: SetComposition|null}
 */
final class DesignSet
{
    public const FORMAT = 'boxlet-design-set';
    public const VERSION = 1;
    /** Larger than any honest design by two orders of magnitude. */
    public const MAX_BYTES = 65536;
    public const MAX_DEPTH = 8;
    public const ID_PATTERN = '~^[a-z0-9][a-z0-9-]{0,31}$~';

    /** Every top-level key, in the order export() writes them. */
    private const KEYS = ['$schema', 'format', 'version', 'id', 'name', 'description', 'author', 'tags', 'decisions', 'look', 'composition'];

    /** The decisions that may be a JSON number as well as a string. */
    private const NUMERIC = ['scale', 'nudge_h1', 'nudge_h2', 'nudge_sm', 'container', 'sheet_width', 'sheet_gap'];

    /**
     * A file's text, checked and cleaned, or the reasons it was refused.
     *
     * The set returned holds every decision, validated and in the order design_tokens
     * stores them; the look with every choice ('' where a design follows its character);
     * and the composition, normalized, or null.
     *
     * @return array{set: ParsedSet|null, errors: list<string>, warnings: list<string>}
     */
    public static function parse(string $json, Blocks $registry): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            return self::refused(t('designset.too_large', ['size' => (string) (self::MAX_BYTES / 1024)]));
        }
        try {
            $raw = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return self::refused($e->getCode() === JSON_ERROR_DEPTH ? t('designset.too_deep', ['depth' => (string) self::MAX_DEPTH]) : t('designset.not_json'));
        }
        if (!is_array($raw) || ($raw['format'] ?? null) !== self::FORMAT) {
            return self::refused(t('designset.not_a_design'));
        }
        if (!is_int($raw['version'] ?? null) || $raw['version'] < 1 || $raw['version'] > self::VERSION) {
            return self::refused(t('designset.version', ['version' => is_scalar($raw['version'] ?? null) ? (string) $raw['version'] : '?']));
        }

        return self::check(self::upgrade($raw), $registry);
    }

    /**
     * A set of an older version, rewritten as the current one. Version 1 is the current
     * one, so there is nothing to do yet; this is where version 2 will say what changed.
     *
     * @param array<mixed> $raw
     * @return array<mixed>
     */
    public static function upgrade(array $raw): array
    {
        return $raw;
    }

    /**
     * A set as the file it is written to: keys in their canonical order, the decisions that
     * only repeat the neutral defaults left out so a file says what is particular to it.
     *
     * @param array<string, string> $name locale => name
     * @param array<string, string> $description locale => description
     * @param array<string, string> $decisions validated decisions
     * @param array<string, string> $look choice => value, '' following the character
     * @param array<string, mixed>|null $composition section, surfaces, dividers, layouts
     */
    public static function export(string $id, array $name, array $description, array $decisions, array $look, ?array $composition, string $author = ''): string
    {
        $neutral = Presets::neutral();
        $kept = [];
        foreach ($decisions as $key => $value) {
            if (!array_key_exists($key, $neutral) || $neutral[$key] !== $value) {
                $kept[$key] = $value;
            }
        }
        $ordered = [];
        foreach (array_keys(ChromeLook::OPTIONS) as $choice) {
            $ordered[$choice] = (string) ($look[$choice] ?? '');
        }

        $set = ['format' => self::FORMAT, 'version' => self::VERSION, 'id' => $id, 'name' => $name];
        if ($description !== []) {
            $set['description'] = $description;
        }
        if ($author !== '') {
            $set['author'] = $author;
        }
        $set['decisions'] = $kept;
        $set['look'] = $ordered;
        if ($composition !== null) {
            $set['composition'] = [
                'section' => array_intersect_key($composition['section'] ?? [], SectionStyle::OPTIONS),
                // Objects even when empty: [] would be read back as a list.
                'surfaces' => (object) ($composition['surfaces'] ?? []),
                'dividers' => (object) ($composition['dividers'] ?? []),
                'layouts' => (object) ($composition['layouts'] ?? []),
            ];
        }

        return json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<mixed> $raw a set of the current version
     * @return array{set: ParsedSet|null, errors: list<string>, warnings: list<string>}
     */
    private static function check(array $raw, Blocks $registry): array
    {
        $errors = [];
        $warnings = [];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $warnings[] = t('designset.unknown_key', ['key' => (string) $key]);
            }
        }

        $id = $raw['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = t('designset.id');
        }
        $name = self::localized($raw['name'] ?? null, 80);
        if ($name === []) {
            $errors[] = t('designset.name');
        }
        $description = self::localized($raw['description'] ?? null, 300);
        $author = is_string($raw['author'] ?? null) ? self::clean($raw['author'], 80) : '';
        $tags = [];
        foreach (is_array($raw['tags'] ?? null) ? array_slice($raw['tags'], 0, 12) : [] as $tag) {
            if (is_string($tag) && ($clean = self::clean($tag, 32)) !== '') {
                $tags[] = $clean;
            }
        }

        $composition = null;
        if (array_key_exists('composition', $raw)) {
            $composition = DesignSetParts::composition($raw['composition'], $registry, $errors, $warnings);
        }
        $decisions = DesignSetParts::decisions($raw['decisions'] ?? null, $errors);
        $look = DesignSetParts::look($raw['look'] ?? null, $composition !== null, $errors);

        if ($errors !== []) {
            return ['set' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        return [
            'set' => [
                'id' => (string) $id,
                'name' => $name,
                'description' => $description,
                'author' => $author,
                'tags' => $tags,
                'decisions' => $decisions,
                'look' => $look,
                'composition' => $composition,
            ],
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * Locale => text, each text one cleaned line. Anything that is not such a pair is left
     * out rather than refused: a name in one language is enough.
     *
     * @return array<string, string>
     */
    private static function localized(mixed $value, int $length): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($locale) && preg_match('~^[a-z]{2}(-[A-Z]{2})?$~', $locale) === 1 && is_string($text)) {
                $clean = self::clean($text, $length);
                if ($clean !== '') {
                    $out[$locale] = $clean;
                }
            }
        }

        return $out;
    }

    /** Trimmed, without control characters, at most $length characters. */
    public static function clean(string $text, int $length): string
    {
        $text = (string) preg_replace('~[\x00-\x1F\x7F]+~u', ' ', $text);

        return trim(mb_substr(trim((string) preg_replace('~\s+~u', ' ', $text)), 0, $length));
    }

    /**
     * @return array{set: null, errors: list<string>, warnings: list<string>}
     */
    private static function refused(string $reason): array
    {
        return ['set' => null, 'errors' => [$reason], 'warnings' => []];
    }

    /** Whether a decision may arrive as a JSON number. */
    public static function numeric(string $key): bool
    {
        return in_array($key, self::NUMERIC, true);
    }
}
