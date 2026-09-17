<?php

namespace App\Modules\Media;

use App\Core\Db;
use Throwable;

/**
 * Generating the variants of a picture, resumably (SPEC §5.5, PLAN.md D-019's sibling
 * problem: a shared host cuts a request off mid-work).
 *
 * Measured on this machine, a 2400×1600 photograph: one full-size AVIF takes 1272 ms
 * through Imagick, and the whole set of five presets in three formats takes about 5.6 s.
 * A 30-second host survives one upload and not two at once. So generation does what it
 * can inside a budget the caller passes, records what it made, and leaves the row
 * incomplete for someone to finish.
 *
 * The budget is a parameter rather than something read from ini here, because
 * max_execution_time is 0 on this machine: read at the point of use, the whole mechanism
 * would be dead code in development and first exercised on a customer's server.
 *
 * Order is priority order. thumb and card are what the library and most pages show, so
 * they exist first even if nothing else does; full is last because it is the most
 * expensive and the least often needed.
 */
final class MediaVariants
{
    /** Cheapest and most needed first. */
    public const ORDER = ['thumb', 'card', 'wide', 'hero', 'full'];

    /** What one encode might take, when deciding whether to start another. */
    private const RESERVE_SECONDS = 2.0;

    public function __construct(
        private readonly Db $db,
        private readonly MediaEncoder $encoder,
        private readonly MediaWriter $writer,
        private readonly string $storagePath,
        private readonly string $publicPath,
    ) {
    }

    /**
     * Generates what is missing, stopping cleanly when the budget runs out.
     *
     * @param float|null $budgetSeconds how long this call may spend; null for no limit
     * @return array{made: list<string>, complete: bool}
     */
    public function generate(int $mediaId, ?float $budgetSeconds = null): array
    {
        $media = $this->db->one('SELECT * FROM media WHERE id = ?', [$mediaId]);
        if ($media === null) {
            return ['made' => [], 'complete' => false];
        }

        $source = $this->storagePath . '/' . (string) $media['path'];
        $orientation = MediaEncoder::orientationOf($source);
        $formats = $this->encoder->formatsFor(self::extensionOf((string) $media['path']));
        $existing = self::decode($media['variants_json'] ?? null);
        $started = microtime(true);
        $made = [];

        foreach (self::ORDER as $preset) {
            $want = $formats;
            $have = $existing[$preset]['formats'] ?? [];
            $missing = array_values(array_diff($want, is_array($have) ? $have : []));
            if ($missing === []) {
                continue;
            }

            foreach ($missing as $format) {
                // Asked BEFORE starting, never during: a check inside an encode cannot
                // stop one, and the point is to stop before the wall rather than at it.
                if ($budgetSeconds !== null && (microtime(true) - $started) + self::RESERVE_SECONDS > $budgetSeconds) {
                    $this->record($mediaId, $existing, false);

                    return ['made' => $made, 'complete' => false];
                }

                $crop = MediaPresets::crop(
                    $preset,
                    (int) $media['width'],
                    (int) $media['height'],
                    (int) $media['focal_x'],
                    (int) $media['focal_y'],
                );
                $relative = MediaPresets::file($preset, $mediaId, (string) $media['filename'], $format);

                try {
                    $result = $this->writer->encode($source, $this->publicPath . '/' . $relative, $crop, $format, $orientation);
                } catch (Throwable) {
                    // One format failing is not the set failing: a host without an AVIF
                    // delegate still gets WebP and the original. The row stays incomplete,
                    // so the attempt is not silently forgotten.
                    continue;
                }

                $existing[$preset]['formats'] = array_values(array_unique(
                    array_merge(is_array($have) ? $have : [], [$format]),
                ));
                $existing[$preset]['width'] = $result['width'];
                $existing[$preset]['height'] = $result['height'];
                $have = $existing[$preset]['formats'];
                $made[] = $preset . '.' . $format;
            }
        }

        $complete = self::isComplete($existing, $formats);
        $this->record($mediaId, $existing, $complete);

        return ['made' => $made, 'complete' => $complete];
    }

    /**
     * The pictures still waiting, oldest first: the library finishes them one at a time.
     *
     * @return list<int>
     */
    public function incomplete(int $limit = 10): array
    {
        $rows = $this->db->all(
            "SELECT id FROM media WHERE status <> 'complete' ORDER BY id LIMIT {$limit}",
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * What a picture has, for rendering: preset => width, height and formats.
     *
     * @param array<string, mixed> $media a media row
     * @return array<string, array{width: int, height: int, formats: list<string>}>
     */
    public static function of(array $media): array
    {
        $variants = [];
        foreach (self::decode($media['variants_json'] ?? null) as $preset => $variant) {
            if (!MediaPresets::exists((string) $preset) || !is_array($variant)) {
                continue;
            }
            $formats = $variant['formats'] ?? [];
            $variants[(string) $preset] = [
                'width' => (int) ($variant['width'] ?? 0),
                'height' => (int) ($variant['height'] ?? 0),
                'formats' => is_array($formats) ? array_values(array_map('strval', $formats)) : [],
            ];
        }

        return $variants;
    }

    /**
     * @param array<string, mixed> $variants
     * @param list<string>         $formats
     */
    private static function isComplete(array $variants, array $formats): bool
    {
        foreach (self::ORDER as $preset) {
            $have = $variants[$preset]['formats'] ?? [];
            if (!is_array($have) || array_diff($formats, $have) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $variants
     */
    private function record(int $mediaId, array $variants, bool $complete): void
    {
        $this->db->query(
            'UPDATE media SET variants_json = ?, status = ? WHERE id = ?',
            [json_encode($variants, JSON_THROW_ON_ERROR), $complete ? 'complete' : 'incomplete', $mediaId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    private static function extensionOf(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
