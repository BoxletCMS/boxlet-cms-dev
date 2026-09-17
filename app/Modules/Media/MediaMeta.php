<?php

namespace App\Modules\Media;

use App\Core\Db;

/**
 * What a picture MEANS, per language: its alt text and its caption (SPEC §5.2).
 *
 * Split from MediaLibrary, which reached the 300-line rule when suggested alt text (D-025)
 * gave both of these more to do. The seam is a real one rather than a line count:
 * MediaLibrary is about the pictures themselves — listing them, finding what uses them,
 * deleting them and their files — while this is about the words attached to them, which
 * live in another table, are edited on a different screen, and are the only part of a
 * picture that differs per language.
 *
 * Static with a Db handed in, which is the idiom the rest of this module already uses
 * (MediaAlt::fill, MediaVariants::of, MediaPicture::resolve): no container entry to add,
 * and nothing here holds state between calls.
 */
final class MediaMeta
{
    /**
     * Alt text and caption for one picture, keyed by locale.
     *
     * `suggested` says Boxlet wrote the alt rather than the owner (D-025), so the screen
     * can ask them to check it instead of presenting a guess as their own words.
     *
     * @return array<string, array{alt: string, caption: string, suggested: bool}>
     */
    public static function forPicture(Db $db, int $mediaId): array
    {
        $meta = [];
        foreach ($db->all('SELECT locale, alt, caption, alt_suggested FROM media_meta WHERE media_id = ?', [$mediaId]) as $row) {
            $meta[(string) $row['locale']] = [
                'alt' => (string) $row['alt'],
                'caption' => (string) ($row['caption'] ?? ''),
                'suggested' => (int) ($row['alt_suggested'] ?? 0) === 1,
            ];
        }

        return $meta;
    }

    /**
     * Saves what a picture means in one locale. An empty alt is meaningful — it says the
     * picture is decorative — so it is stored rather than treated as "not filled in".
     *
     * SAVING IS CONFIRMING (D-025): alt_suggested is cleared whatever the text says, even
     * when the owner leaves a suggestion word for word. Clearing it only on a change would
     * leave an approved suggestion still asking to be checked, with no way to silence the
     * badge except editing words they already agree with.
     */
    public static function save(Db $db, int $mediaId, string $locale, string $alt, string $caption): void
    {
        $existing = $db->one(
            'SELECT id FROM media_meta WHERE media_id = ? AND locale = ?',
            [$mediaId, $locale],
        );

        if ($existing === null) {
            $db->query(
                'INSERT INTO media_meta (media_id, locale, alt, caption, alt_suggested) VALUES (?, ?, ?, ?, 0)',
                [$mediaId, $locale, substr($alt, 0, 255), $caption],
            );

            return;
        }

        $db->query(
            'UPDATE media_meta SET alt = ?, caption = ?, alt_suggested = 0 WHERE media_id = ? AND locale = ?',
            [substr($alt, 0, 255), $caption, $mediaId, $locale],
        );
    }
}
