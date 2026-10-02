<?php

namespace App\Modules\Demo;

use App\Core\Db;
use App\Modules\Media\MediaController;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaMeta;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;
use Closure;

/**
 * The demo's six pictures (the owner's own illustrations, free to distribute; PLAN.md D-176),
 * shipped in install/demo/ and taken into the media library as an upload would be: every
 * size made, and an alt text in each of the demo's two languages.
 *
 * pages.php names a picture as `demo-picture:<name>` in a media field; DemoSite puts the id
 * there, or leaves the field empty when the pictures were not imported (the tests' seed).
 */
final class DemoPictures
{
    /** name => [English alt, Croatian alt] */
    public const PICTURES = [
        'hero-living-room' => ['Living room with an arched window', 'Dnevni boravak s lučnim prozorom'],
        'card-homes' => ['Bedroom corner with an arched niche', 'Kut spavaće sobe s lučnom nišom'],
        'card-offices' => ['Office with a long desk and pendant lamps', 'Ured s dugim stolom i visećim svjetiljkama'],
        'card-shops' => ['Shop shelving with a counter', 'Police trgovine s pultom'],
        'process-plan' => ['Floor plan on a desk', 'Tlocrt na stolu'],
        'about-studio' => ['Studio table with material samples', 'Stol u studiju s uzorcima materijala'],
    ];

    /**
     * What stores one picture and makes its sizes, from the site's own folders: the installer
     * and `php migrations/seed.php` both build it, the one before the container exists.
     *
     * @return Closure(string, string): int the media id
     */
    public static function importer(Db $db, string $storage, string $public): Closure
    {
        $encoder = new MediaEncoder();
        $upload = new MediaUpload($db, $storage, $encoder);
        $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

        return static function (string $file, string $name) use ($upload, $variants): int {
            // A copy, because an upload is moved into storage and the package's file must stay.
            $temporary = (string) tempnam(sys_get_temp_dir(), 'demo');
            copy($file, $temporary);
            $stored = $upload->store($temporary, $name);
            @unlink($temporary);
            $id = (int) $stored['id'];
            // EVERY SIZE, AND NEVER PAST THE REQUEST'S LIMIT. The six take about sixteen
            // seconds here, inside the installer's one request; a slow host may give it
            // thirty. Each picture asks for its own time where the host allows that, and
            // where it does not, what the budget leaves is finished from the library
            // (Finish), rather than the install dying half-way with a fatal error.
            // Only a limit there is: a command line with none must not be given one, which
            // the whole test suite once inherited from this line.
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0) {
                @set_time_limit(max($limit, 60));
            }
            $variants->generate($id, MediaController::budget(microtime(true)));

            return $id;
        };
    }

    /**
     * Each picture stored, with its alt text in the site's language ($lang's words).
     *
     * @param Closure(string, string): int $store
     * @return array<string, int> name => media id
     */
    public static function import(Db $db, Closure $store, string $locale, string $lang): array
    {
        $ids = [];
        foreach (self::PICTURES as $name => $words) {
            // A server that cannot take a picture (no image extension) still gets the demo:
            // that picture's field stays empty, as it would without the pictures at all.
            try {
                $id = $store(dirname(__DIR__, 3) . '/install/demo/' . $name . '.jpg', $name . '.jpg');
            } catch (\Throwable $e) {
                error_log('Demo picture ' . $name . ': ' . $e->getMessage());
                continue;
            }
            self::describe($db, $id, $locale, $lang === 'hr' ? $words[1] : $words[0]);
            $ids[$name] = $id;
        }

        return $ids;
    }

    /**
     * Each picture's alt text in one more language, once the site has it: a description
     * names its language, and the demo's translation adds that language after the pictures.
     *
     * @param array<string, int> $ids name => media id
     */
    public static function translate(Db $db, array $ids, string $locale): void
    {
        foreach ($ids as $name => $id) {
            self::describe($db, $id, $locale, $locale === 'hr' ? self::PICTURES[$name][1] : self::PICTURES[$name][0]);
        }
    }

    private static function describe(Db $db, int $id, string $locale, string $alt): void
    {
        MediaMeta::save($db, $id, $locale, $alt, '');
    }

    /**
     * A block's content with each `demo-picture:<name>` replaced by that picture's id, or by
     * nothing when there is no such picture — in its fields and in its repeaters' items.
     *
     * @param array<string, mixed> $content
     * @param array<string, int> $pictures
     * @return array<string, mixed>
     */
    public static function place(array $content, array $pictures): array
    {
        foreach ($content as $name => $value) {
            if (is_string($value) && str_starts_with($value, 'demo-picture:')) {
                $content[$name] = $pictures[substr($value, 13)] ?? null;
            } elseif (is_array($value)) {
                $content[$name] = self::place($value, $pictures);
            }
        }

        return $content;
    }
}
