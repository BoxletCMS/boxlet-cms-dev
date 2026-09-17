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
                'file' => MediaPresets::file($preset, $id, (string) $media['filename'], $format),
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
