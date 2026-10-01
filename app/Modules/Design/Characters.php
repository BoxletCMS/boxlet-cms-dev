<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use Closure;

/**
 * Every character a site can choose, wherever it comes from (PLAN.md D-152): the five
 * Boxlet ships in designs/core/, sets an owner dropped into designs/custom/ over FTP
 * (D-155), and — from migration 0032 — sets imported in the admin.
 *
 * WHAT A CHARACTER IS lives in its file now, not in PHP: the decisions it makes, the
 * header and footer it gives, the composition it gives a page. This is the one reader of
 * all three, read once per request; Presets, Composition and ChromeLook ask it.
 *
 * CORE IS TRUSTED, NOT VALIDATED, AS IT LOADS. Tokens::validate() falls back to the default
 * character, so validating the characters on the way in would call itself. The five files
 * are held instead by tests: to the snapshot of what they were as PHP constants
 * (design_parity_test.php), and to Tokens::validate() and DesignSet::parse() without an
 * error, a warning or a changed value (characters_test.php). Everything else is read
 * through DesignSet::parse(), and a file it refuses is left out — never the site.
 *
 * Precedence when two share an id: core, then custom, then imported. A custom file that
 * cannot be used is noted in skipped(), which the Appearance screen shows as a notice.
 */
final class Characters
{
    /** The five Boxlet ships, in the order the screen has always shown them. */
    public const CORE = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

    /** The decisions no character makes, as every character's decisions are completed. */
    private const NO_COLOURS_BY_HAND = [
        'color_background' => '', 'color_card' => '', 'color_surface' => '', 'color_border' => '',
        'color_text' => '', 'color_muted' => '', 'color_link' => '',
    ];

    /** No character nudges a step or overrides the pairing's heading treatment (D-066). */
    private const NOTHING_NUDGED = [
        'nudge_h1' => '0', 'nudge_h2' => '0', 'nudge_sm' => '0',
        'heading_weight' => '', 'tracking' => '', 'caps' => '',
    ];

    /* The sheet as every character has drawn it: three units of frame, square corners, no
     * lift, chrome inside the sheet, the footer's contents lined up with the page's (D-067,
     * D-116). Soft is the one that is boxed, so it is the one where any of this shows. */
    private const SHEET = [
        'frame' => 'normal', 'sheet_width' => '80', 'sheet_gap' => '3', 'sheet_radius' => 'square', 'sheet_shadow' => 'none',
        'header_bleed' => 'sheet', 'footer_bleed' => 'sheet',
        'footer_width' => 'content',
    ];

    /** @var array<string, array{source: string, set: array<string, mixed>}>|null id => where it came from and what it is */
    private static ?array $all = null;
    /** @var list<array{file: string, reason: string}> */
    private static array $skipped = [];
    private static string $custom = '';
    private static ?Closure $registry = null;

    /**
     * Where custom files are and how to reach the block registry, set once by the bootstrap.
     * Without it — a script, a test that never booted — only the five core characters exist.
     *
     * @param Closure(): Blocks $registry
     */
    public static function use(string $customDirectory, Closure $registry): void
    {
        self::$custom = $customDirectory;
        self::$registry = $registry;
        self::reset();
    }

    /** Forgets what was read, so the next question reads again. */
    public static function reset(): void
    {
        self::$all = null;
        self::$skipped = [];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /** 'core', 'custom' or 'imported'; '' for an id that is no character. */
    public static function source(string $id): string
    {
        return self::all()[$id]['source'] ?? '';
    }

    /**
     * The layer-1 decisions of a character, every one of them, in the order validate()
     * stores them; the default character's for an id that is none.
     *
     * THE ORDER validate() STORES IN, spelled once: what a character gives, with the
     * decisions no character makes merged into their places rather than appended. A test
     * compares a character with what validation returns, and an array whose keys are in
     * another order is a different array. A set that does make one of them — an owner's
     * exception, which an imported set may carry (D-153) — has it put in its place by the
     * array_replace(), never moved to the end.
     *
     * @return array<string, string>
     */
    public static function decisions(string $id): array
    {
        $all = self::all();
        $preset = ($all[$id] ?? $all[Presets::DEFAULT])['set']['decisions'];

        return array_replace(
            ['seed' => $preset['seed'], 'secondary' => $preset['secondary']]
                + self::NO_COLOURS_BY_HAND
                + ['typography' => $preset['typography'], 'text_size' => $preset['text_size'], 'scale' => $preset['scale']]
                + self::NOTHING_NUDGED
                + $preset
                /* NO CHARACTER BOXLET SHIPS GIVES ONE OF THE THREE A COLOUR OF ITS OWN (D-076).
                   The shades of the palette are what a character IS. Split in two because they
                   sit on either side of the sheet in the order validate() stores. */
                + ['page_background_colour' => '']
                + self::SHEET
                + ['header_colour' => '', 'footer_colour' => ''],
            $preset,
        );
    }

    /**
     * The decisions no character makes: what a set that leaves a key out means by it.
     *
     * @return array<string, string>
     */
    public static function neutral(): array
    {
        return self::NO_COLOURS_BY_HAND + self::NOTHING_NUDGED
            + ['page_background_colour' => ''] + self::SHEET + ['header_colour' => '', 'footer_colour' => ''];
    }

    /**
     * Layers 2 and 3 of a character: `section` is the style every block starts from,
     * `surfaces` and `dividers` what one block type does differently, `layouts` the
     * arrangement of a type that offers several. The default character's for an id that is
     * none; Composition keeps its own answer for that case (SectionStyle::DEFAULTS), which
     * is what a site has always had.
     *
     * @return array{section: array<string, string>, surfaces: array<string, string>, dividers: array<string, string>, layouts: array<string, string>}
     */
    public static function composition(string $id): array
    {
        $all = self::all();

        return ($all[$id] ?? $all[Presets::DEFAULT])['set']['composition'];
    }

    /**
     * What a character gives the header and footer, every choice set; the default
     * character's for an id that is none.
     *
     * @return array<string, string>
     */
    public static function look(string $id): array
    {
        $all = self::all();

        return ($all[$id] ?? $all[Presets::DEFAULT])['set']['look'];
    }

    /**
     * A character's name as the admin shows it. Boxlet's own come from the lang files, as
     * they always have, so a translation of the admin carries them; a custom or imported one
     * from its own file, in the admin's language or English, then the lang key, then its id.
     */
    public static function label(string $id): string
    {
        return self::words($id, 'name', 'design.preset.' . $id);
    }

    /** The line under a character's name, found the same way as label(). */
    public static function hint(string $id): string
    {
        return self::words($id, 'description', 'design.preset.' . $id . '_hint');
    }

    /**
     * The custom files left out, and why: not JSON, refused by validation, no composition,
     * or an id another character already has.
     *
     * @return list<array{file: string, reason: string}>
     */
    public static function skipped(): array
    {
        self::all();

        return self::$skipped;
    }

    private static function words(string $id, string $field, string $key): string
    {
        $entry = self::all()[$id] ?? null;
        $translated = t($key);
        $has = $translated !== $key;
        if ($entry === null || $entry['source'] === 'core') {
            return $has ? $translated : (string) ($entry['set'][$field]['en'] ?? $id);
        }
        $own = $entry['set'][$field];

        return (string) ($own['en'] ?? reset($own) ?: ($has ? $translated : ($field === 'name' ? $id : '')));
    }

    /**
     * @return array<string, array{source: string, set: array<string, mixed>}>
     */
    private static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        // Core first and on its own: reading a custom file validates it, validating asks for
        // the default character, and the default character must already be here to answer.
        self::$all = [];
        foreach (self::CORE as $id) {
            $set = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/designs/core/' . $id . '.json'), true);
            if (is_array($set)) {
                self::$all[$id] = ['source' => 'core', 'set' => $set];
            }
        }

        $custom = self::custom();
        uasort($custom, static fn (array $a, array $b): int => strcasecmp((string) ($a['set']['name']['en'] ?? reset($a['set']['name'])), (string) ($b['set']['name']['en'] ?? reset($b['set']['name']))));
        self::$all += $custom;

        return self::$all;
    }

    /**
     * designs/custom/*.json, each through DesignSet::parse(). Read, never written (D-155).
     *
     * @return array<string, array{source: string, set: array<string, mixed>}>
     */
    private static function custom(): array
    {
        if (self::$custom === '' || self::$registry === null || !is_dir(self::$custom)) {
            return [];
        }
        $found = [];
        $files = glob(self::$custom . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $read = DesignSet::parse((string) @file_get_contents($file), (self::$registry)());
            $set = $read['set'];
            if ($set === null) {
                self::$skipped[] = ['file' => $name, 'reason' => implode(' ', $read['errors'])];
            } elseif ($set['composition'] === null) {
                self::$skipped[] = ['file' => $name, 'reason' => t('characters.not_a_character')];
            } elseif (isset(self::$all[$set['id']]) || isset($found[$set['id']])) {
                self::$skipped[] = ['file' => $name, 'reason' => t('characters.id_taken', ['id' => $set['id']])];
            } else {
                $found[$set['id']] = ['source' => 'custom', 'set' => $set];
            }
        }

        return $found;
    }
}
