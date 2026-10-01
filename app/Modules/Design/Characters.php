<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Core\Db;
use Closure;

/**
 * Every character a site can choose, wherever it comes from (PLAN.md D-152): the five
 * Boxlet ships in designs/core/, sets an owner dropped into designs/custom/ over FTP
 * (D-155), and sets imported in the admin, kept in design_characters (migration 0032).
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
 * Precedence when two share an id: core, then custom, then imported. A custom file or an
 * imported set that cannot be used is noted in skipped(), which the Appearance screen shows
 * as a notice. An import never takes an id that is in use: addImported() gives it `-2`,
 * `-3`… so a second `soft` cannot replace the first, or the one Boxlet ships.
 *
 * @phpstan-import-type ParsedSet from DesignSet
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
    private static ?Closure $db = null;

    /**
     * Where custom files are, and how to reach the block registry and the database, set once
     * by the bootstrap. Without it — a script, a test that never booted — only the five core
     * characters exist.
     *
     * @param Closure(): Blocks $registry
     * @param (Closure(): Db)|null $db
     */
    public static function use(string $customDirectory, Closure $registry, ?Closure $db = null): void
    {
        self::$custom = $customDirectory;
        self::$registry = $registry;
        self::$db = $db;
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
     * The custom files and imported sets left out, and why: not JSON, refused by validation,
     * no composition, or an id another character already has.
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
        self::$all = CharacterSources::core();
        if (self::$registry !== null) {
            $registry = (self::$registry)();
            $sources = [
                'custom' => CharacterSources::files(self::$custom),
                'imported' => self::$db !== null ? CharacterSources::rows((self::$db)()) : [],
            ];
            foreach ($sources as $source => $items) {
                self::$all += CharacterSources::read($items, $source, $registry, self::$all, self::$skipped);
            }
        }

        return self::$all;
    }

    /**
     * Keeps a set, as parsed, as an imported character, under an id no character has yet:
     * its own, or its own with `-2`, `-3`… The name is left as it is. Returns the id it was
     * kept under.
     *
     * @param ParsedSet $set
     */
    public static function addImported(Db $db, array $set, string $source = 'import'): string
    {
        $taken = array_flip(self::names());
        foreach ($db->all('SELECT slug FROM design_characters') as $row) {
            $taken[(string) $row['slug']] = true;
        }
        $slug = $set['id'];
        for ($n = 2; isset($taken[$slug]); $n++) {
            // Shortened to make room, so the id still fits character_name VARCHAR(32).
            $suffix = '-' . $n;
            $slug = rtrim(substr($set['id'], 0, 32 - strlen($suffix)), '-') . $suffix;
        }

        $now = gmdate('Y-m-d H:i:s');
        $db->query(
            'INSERT INTO design_characters (slug, set_json, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$slug, DesignSet::export($slug, $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition'], $set['author']), $source, $now, $now],
        );
        self::reset();

        return $slug;
    }

    /**
     * Removes an imported character. Only an imported one: a core character is Boxlet's and a
     * custom one is a file the owner put there (D-155). A site composed with it falls back to
     * the default character, as Composition::active() does for any id that is gone.
     */
    public static function deleteImported(Db $db, string $slug): bool
    {
        if (self::source($slug) !== 'imported') {
            return false;
        }
        $db->query('DELETE FROM design_characters WHERE slug = ?', [$slug]);
        self::reset();

        return true;
    }

}
