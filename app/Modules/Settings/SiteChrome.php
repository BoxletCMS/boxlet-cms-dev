<?php

namespace App\Modules\Settings;

use App\Core\Db;
use App\Core\Settings;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaPresets;
use App\Modules\Media\MediaVariants;
use App\Support\Url;

/**
 * What the site's own settings mean to a page's <head> (PLAN.md D-028): the icon a
 * browser puts on the tab, and the picture a link to this site shows when the page has
 * none of its own.
 *
 * Both go through the media pipeline rather than being special cases. A favicon is a
 * picture in the library like any other, so it already has every preset generated on
 * upload — there is nothing to add and nothing to regenerate.
 *
 * NO logo() HERE YET. The header that would use it is 5c; a method with no caller is a
 * guess about what that screen will need.
 */
final class SiteChrome
{
    /**
     * The tab icon, or null when none is chosen or its variants are not made yet.
     *
     * THE `thumb` PRESET, 200×200, rather than new favicon presets. It is the only square
     * one and it is already generated for every picture. Dedicated 32 and 180 presets
     * would mean regenerating every existing picture in every library — SPEC §5.5 states
     * that cost itself — to save a browser a downscale it does anyway. 200×200 covers the
     * 16, 32 and 48 a desktop asks for and Apple's 180.
     *
     * THE ORIGINAL FORMAT IS PREFERRED over AVIF and WebP here, which is the opposite of
     * what a <picture> wants. A favicon has no srcset to fall back through: the browser
     * takes the one URL given, and a PNG is read by everything while a WebP icon is not.
     *
     * @return array{url: string, type: string}|null
     */
    public static function icon(Db $db): ?array
    {
        $variant = self::variant($db, 'site_favicon', ['thumb'], true);

        return $variant === null ? null : ['url' => Url::asset($variant['file']), 'type' => $variant['type']];
    }

    /**
     * The default sharing picture as an absolute URL, or null when none is chosen.
     *
     * `wide` is 1200×630, which is the shape every crawler crops to anyway; `hero` is the
     * fallback for a library where wide has not been generated yet. Absolute because the
     * fetcher has no page to resolve a relative path against.
     */
    public static function shareImage(Db $db): ?string
    {
        $variant = self::variant($db, 'site_share_image', ['wide', 'hero'], true);

        return $variant === null ? null : Url::absolute($variant['file']);
    }

    /**
     * What the header block is drawn from (PLAN.md D-028, D-030).
     *
     * The shape is the block's own content, so the renderer hands it straight to
     * Blocks::render() and the template asks nothing. There is NO menu here: the menu is
     * named once for the whole site and resolved per locale by MenuTree::forVisitors().
     *
     * @return array{logo: int|null, logo_dark: int|null, button: array{label: string, url: string}}
     */
    public static function header(Db $db, string $locale): array
    {
        $values = Settings::many($db, [
            self::key('chrome_logo'),
            self::key('chrome_button_label', $locale),
            self::key('chrome_button_url', $locale),
        ], '');

        $logo = self::logo($db);

        return [
            'logo' => $logo,
            // The logo for dark surfaces (D-112), set under Branding beside the first; the
            // renderer chooses between them by the ink on the bar.
            'logo_dark' => Settings::mediaId($db, 'site_logo_dark'),
            'button' => [
                'label' => self::string($values[self::key('chrome_button_label', $locale)] ?? ''),
                'url' => self::string($values[self::key('chrome_button_url', $locale)] ?? ''),
            ],
        ];
    }

    /**
     * What the footer block is drawn from. Both fields are the owner's own words, so both
     * are per locale — a Croatian page with an English small print is the kind of thing
     * nobody notices until a visitor does.
     *
     * @return array{text: string, small_print: string}
     */
    public static function footer(Db $db, string $locale): array
    {
        $values = Settings::many($db, [
            self::key('chrome_footer_text', $locale),
            self::key('chrome_small_print', $locale),
        ], '');

        return [
            'text' => self::string($values[self::key('chrome_footer_text', $locale)] ?? ''),
            'small_print' => self::string($values[self::key('chrome_small_print', $locale)] ?? ''),
        ];
    }

    /**
     * The NAME of the menu the chrome shows, not its id.
     *
     * A name resolves inside whichever locale is being rendered — menus are unique per
     * (locale, name) — so one stored value gives each translation its own menu, and there
     * is no id left dangling when a menu is deleted and made again.
     */
    public static function menuName(Db $db): string
    {
        return Settings::text($db, self::key('chrome_menu'));
    }

    /**
     * A menu was renamed: if the chrome showed it under its old name, it shows the new one.
     */
    public static function followRename(Db $db, string $old, string $new): void
    {
        if (self::menuName($db) === $old) {
            Settings::set($db, self::key('chrome_menu'), $new);
        }
    }

    /**
     * What the chrome screen writes: the choices that are the same in every language.
     *
     * The menu is stored by NAME. Menus are unique per (locale, name), so one name gives
     * each translation its own menu and nothing dangles when a menu is deleted and made
     * again. An id would have had to be re-chosen, per locale, every time.
     */
    public static function saveShared(Db $db, string $menu): void
    {
        Settings::set($db, self::key('chrome_menu'), $menu);
    }

    /**
     * THE SITE'S ONE LOGO, set under Settings → Branding (D-038). There used to be two:
     * site_logo, which nothing drew, and the header's chrome_logo, which the header drew.
     * The header's is read as a fallback so a site that set it keeps its logo until the
     * owner saves Branding, which retires it (retireHeaderLogo()).
     */
    public static function logo(Db $db): ?int
    {
        return Settings::mediaId($db, 'site_logo') ?? Settings::mediaId($db, self::key('chrome_logo'));
    }

    /** Called when Branding is saved: from then on site_logo alone is the logo. */
    public static function retireHeaderLogo(Db $db): void
    {
        Settings::set($db, self::key('chrome_logo'), null);
    }

    /**
     * What the chrome screen writes for one language: the owner's own words.
     *
     * Callers pass FIELD NAMES, never keys. The locale suffix is this class's business and
     * stays in key(); a controller spelling it out would be the sixth hand-rolled copy of a
     * contract, which is the mistake Settings itself was written to end.
     *
     * @param array{button_label: string, button_url: string, text: string, small_print: string} $values
     */
    public static function saveForLocale(Db $db, string $locale, array $values): void
    {
        Settings::set($db, self::key('chrome_button_label', $locale), $values['button_label']);
        Settings::set($db, self::key('chrome_button_url', $locale), $values['button_url']);
        Settings::set($db, self::key('chrome_footer_text', $locale), $values['text']);
        Settings::set($db, self::key('chrome_small_print', $locale), $values['small_print']);
    }

    /**
     * The chrome's look as stored, one raw value per choice of ChromeLook::OPTIONS, which
     * validates it. Read here because this class is where chrome keys are spelled.
     *
     * @param list<string> $names
     * @return array<string, mixed> name => stored value, '' when nothing is stored
     */
    public static function look(Db $db, array $names): array
    {
        $keys = array_map(static fn (string $name): string => self::key('chrome_look_' . $name), $names);
        $values = Settings::many($db, $keys, '');

        return array_combine($names, array_map(static fn (string $key): mixed => $values[$key] ?? '', $keys));
    }

    /**
     * @param array<string, string> $look name => value, already checked by ChromeLook
     */
    public static function saveLook(Db $db, array $look): void
    {
        foreach ($look as $name => $value) {
            Settings::set($db, self::key('chrome_look_' . $name), $value);
        }
    }

    /**
     * THE ONLY PLACE A CHROME SETTINGS KEY IS COMPOSED.
     *
     * `settings` is one JSON value per key with no locale column, and adding one would be
     * a migration on a table six callers already read, to express something a key can say.
     * So a language-specific value carries its locale in the key — and that shape lives
     * here, once. Settings itself exists because six places had grown their own copy of the
     * same three lines; a suffix spelled out at each call site would be that all over again.
     */
    private static function key(string $name, ?string $locale = null): string
    {
        return $locale === null ? $name : $name . ':' . $locale;
    }

    /**
     * A stored value as a string. A settings table edited by hand is a real thing on a
     * shared host, and a header that fatals on one bad row is worse than one that is blank.
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The first of $presets this picture actually has, as a file path and a MIME type.
     *
     * @param list<string> $presets in order of preference
     * @param bool $originalFirst prefer the source's own format over AVIF and WebP
     * @return array{file: string, type: string}|null
     */
    private static function variant(Db $db, string $key, array $presets, bool $originalFirst): ?array
    {
        $id = Settings::mediaId($db, $key);
        if ($id === null) {
            return null;
        }

        // A picture chosen here can be deleted from the library afterwards; the setting is
        // not a foreign key and nothing stops that. Missing reads as "none chosen".
        $media = $db->one('SELECT * FROM media WHERE id = ?', [$id]);
        if ($media === null) {
            return null;
        }

        $variants = MediaVariants::of($media);
        foreach ($presets as $preset) {
            $formats = $variants[$preset]['formats'] ?? [];
            if ($formats === []) {
                continue;
            }
            $format = self::choose($formats, $originalFirst);

            return [
                'file' => MediaPresets::file($preset, $id, (string) $media['filename'], $format) . MediaPresets::version((string) ($media['hash'] ?? ''), (int) ($media['revision'] ?? 0)),
                'type' => 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
            ];
        }

        return null;
    }

    /**
     * @param list<string> $formats
     */
    private static function choose(array $formats, bool $originalFirst): string
    {
        if ($originalFirst) {
            // Whatever is not one of the generated formats is the source's own, which
            // formatsFor() always adds when the server can write it.
            $original = array_values(array_diff($formats, MediaEncoder::FORMATS));
            if ($original !== []) {
                return $original[0];
            }
        }

        return in_array('webp', $formats, true) ? 'webp' : $formats[0];
    }
}
