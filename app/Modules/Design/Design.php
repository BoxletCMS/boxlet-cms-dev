<?php

namespace App\Modules\Design;

use App\Core\Db;
use App\Core\Settings;
use App\Modules\Design\Vocabulary\Decisions;
use Throwable;

/**
 * The site's saved design (PLAN.md D-164): the OWNER'S values of the global decisions — the
 * header and footer included — one row in design_tokens per value set, nothing for a key that
 * follows; compiled, over the character the site composes with, into the stylesheet whose
 * file name is recorded in settings.tokens_css.
 *
 * Changing the character therefore keeps every value the owner set (README 1.1): the rows do
 * not move, only what fills the keys they leave empty.
 */
final class Design
{
    /** Where font files live as seen from the compiled stylesheet in public/cache. */
    private const FONTS_FROM_CACHE = '../assets/fonts';

    /**
     * The owner's values: every key of the vocabulary, '' for one that follows. A stored value
     * the vocabulary no longer takes is '' rather than an error, so a damaged row can never
     * break rendering.
     *
     * @return array<string, string>
     */
    public static function load(Db $db): array
    {
        $stored = [];
        foreach ($db->all('SELECT group_key, value_json FROM design_tokens') as $row) {
            $stored[(string) $row['group_key']] = json_decode((string) $row['value_json'], true);
        }
        $stored = Tokens::upgraded($stored);
        $values = [];
        foreach (array_keys(Decisions::ALL) as $key) {
            $value = $stored[$key] ?? '';
            $values[$key] = Decisions::clean($key, is_int($value) || is_float($value) ? (string) $value : $value) ?? '';
        }

        return $values;
    }

    /**
     * The design as drawn: the owner's values over the character's.
     *
     * @return array<string, string>
     */
    public static function resolved(Db $db, ?string $character = null): array
    {
        return Tokens::resolve(self::load($db), $character ?? Composition::active($db));
    }

    /**
     * Stores the owner's values — every key that is not '' — and publishes the stylesheet.
     *
     * @param array<string, string> $values key => value, '' or absent for "follow"
     * @return string the new stylesheet's file name
     */
    public static function save(Db $db, array $values, string $cacheDirectory): string
    {
        self::store($db, $values);

        return self::publish($db, $cacheDirectory);
    }

    /**
     * Writes the owner's values without compiling: every row replaced by the keys given.
     *
     * @param array<string, string> $values
     */
    public static function store(Db $db, array $values): void
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $db->query('DELETE FROM design_tokens');
            foreach (array_keys(Decisions::ALL) as $key) {
                $value = (string) ($values[$key] ?? '');
                if ($value !== '') {
                    $db->query('INSERT INTO design_tokens (group_key, value_json) VALUES (?, ?)', [$key, json_encode($value, JSON_THROW_ON_ERROR)]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Compiles the design as drawn to cache/tokens.{hash}.css and records the file name.
     */
    public static function publish(Db $db, string $cacheDirectory): string
    {
        $resolved = self::resolved($db);
        $file = (new TokenCompiler())->compile(
            Derived::from($resolved, Composition::section(Composition::active($db), [])['width']),
            $cacheDirectory,
            Typography::fontFaces([$resolved['heading_font'], $resolved['body_font']], self::FONTS_FROM_CACHE),
        );
        Settings::set($db, 'tokens_css', $file);

        return $file;
    }

    /**
     * The stylesheet file every page links. Normally a settings read; it compiles only when
     * the recorded file is missing, so a site never renders unstyled.
     */
    public static function stylesheet(Db $db, string $cacheDirectory): string
    {
        $file = Settings::get($db, 'tokens_css');
        if (is_string($file) && preg_match('~^tokens\.[0-9a-f]{12}\.css$~', $file) && is_file($cacheDirectory . '/' . $file)) {
            return $file;
        }

        return self::publish($db, $cacheDirectory);
    }
}
