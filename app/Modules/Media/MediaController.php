<?php

namespace App\Modules\Media;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Admin\AdminView;
use App\Support\Bytes;
use App\Support\Url;
use Throwable;

/**
 * Admin: the picture library — what is in it, and putting more in.
 *
 * One picture on its own is MediaItemController. This is the list and the upload.
 *
 * UPLOADING IS THE PART THAT FAILS ON A REAL SERVER, and it fails in three different
 * places. Each one has to say something a person can act on:
 *
 *   nginx        refuses a body over client_max_body_size — 1 MB by default — and answers
 *                413 itself, before PHP runs at all. Nothing here can catch that, which
 *                is why media.js checks the size in the browser and says so rather than
 *                letting the submit produce a server error page nobody can read.
 *   PHP          discards a post over post_max_size entirely: $_POST and $_FILES both
 *                arrive empty. The router tells that case from an expired form, because
 *                the missing CSRF token is a symptom and not the cause.
 *   the encoder  runs out of execution time part-way through the variants. That is what
 *                the budget below and the Finish button are for: a picture that did not
 *                finish is listed as unfinished, rather than silently half-made.
 */
final class MediaController
{
    /** Kept back for the redirect itself, after the last encode. */
    private const RESERVE_SECONDS = 3.0;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, string $locale, array $params): Response
    {
        $search = $request->query['q'] ?? '';
        $search = is_string($search) ? trim($search) : '';

        $pictures = [];
        foreach ($this->library()->all($search) as $row) {
            $pictures[] = self::card($row);
        }

        // The picker asks for the same listing with no screen around it: one query, one
        // card, one set of markup, so the library and the picker cannot drift apart. HTML
        // rather than JSON, because the server answers with markup everywhere in this
        // admin and a second representation would be a second thing to keep correct.
        if (($request->query['picker'] ?? '') !== '') {
            return Response::admin((new View(__DIR__ . '/views'))->render('admin/cards', $locale, [
                'pictures' => $pictures,
                'search' => $search,
                'picking' => true,
                'csrf' => $this->container->get('session')->csrfToken(),
            ], null));
        }

        return AdminView::render($this->container, __DIR__ . '/views', 'admin/index', [
            'title' => t('media.title'),
            'nav' => 'media',
            'styles' => ['admin-media.css'],
            'scripts' => ['media.js'],
            'wide' => true,
            'pictures' => $pictures,
            'search' => $search,
            'limits' => Bytes::limits(),
        ]);
    }

    /**
     * Stores each chosen file and makes as many variants as the clock allows.
     *
     * The router has already checked the CSRF token, and has already answered a post PHP
     * discarded for being too large, so by here the request arrived whole.
     *
     * One budget covers the whole batch rather than one per file: five pictures at six
     * seconds each is thirty seconds, and a shared host would kill the request somewhere
     * in the middle of the fourth. What is left over is left incomplete on purpose.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, string $locale, array $params): Response
    {
        $started = microtime(true);
        $messages = [];
        $stored = 0;

        $chosen = self::chosen($request);
        if ($chosen === []) {
            $messages[] = t('media.no_file');
        }

        foreach ($chosen as $file) {
            $problem = MediaUpload::problem($file['error']);
            if ($problem !== null) {
                // An empty slot among several inputs is normal and not worth reporting;
                // anything else names the file it happened to.
                if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $messages[] = $file['name'] . ': ' . $problem;
                }
                continue;
            }

            try {
                $result = $this->container->get('media_upload')->store($file['tmp_name'], $file['name']);
            } catch (Throwable $e) {
                // A refusal names the file: with several chosen at once, "that is not a
                // picture Boxlet accepts" without a name is not actionable.
                $messages[] = $file['name'] . ': ' . $e->getMessage();
                continue;
            }

            if ($result['duplicate']) {
                $messages[] = t('media.duplicate', ['name' => $file['name']]);
                continue;
            }

            $stored++;
            $this->container->get('media_variants')->generate($result['id'], self::budget($started));
        }

        if ($stored > 0) {
            array_unshift($messages, t('media.uploaded', ['count' => (string) $stored]));
        }
        $this->container->get('session')->set('flash', implode(' ', $messages));

        return Response::redirect(Url::admin('media'));
    }

    /**
     * Makes the variants a cut-off upload never reached.
     *
     * One picture per press, deliberately: a host that killed the first request would
     * kill an identical second one, and pressing Finish twice should make progress twice
     * rather than repeat the same failure.
     *
     * @param array<string, string> $params
     */
    public function finish(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        $media = $this->library()->find($id);
        if ($media === null) {
            return self::missing();
        }

        $this->container->get('media_variants')->generate($id, self::budget(microtime(true)));
        $this->container->get('session')->set('flash', t('media.finished', ['name' => (string) $media['filename']]));

        return Response::redirect(Url::admin('media'));
    }

    public static function missing(): Response
    {
        return Response::admin(e(t('media.not_found')), 404);
    }

    /**
     * What a list entry shows for one picture, including the thumbnail to draw it with.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, filename: string, original: string, size: string, width: int, height: int, complete: bool, thumb: string|null}
     */
    public static function card(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'filename' => (string) $row['filename'],
            'original' => (string) $row['original_name'],
            'size' => Bytes::human((int) $row['size']),
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'complete' => (string) $row['status'] === 'complete',
            'thumb' => self::variant($row, 'thumb'),
        ];
    }

    /**
     * The URL of one preset of a picture, or null when that preset has not been made.
     *
     * The admin asks for a single file rather than a <picture> with sources: this is a
     * 200 px square in a list, the saving would be a few kilobytes, and format
     * negotiation belongs to the front end where the bytes actually matter. WebP first
     * because every browser that can run this admin reads it, then whatever the original
     * format was, which is always written.
     *
     * @param array<string, mixed> $row
     */
    public static function variant(array $row, string $preset): ?string
    {
        $formats = MediaVariants::of($row)[$preset]['formats'] ?? [];
        if ($formats === []) {
            return null;
        }

        return Url::asset(MediaPresets::file(
            $preset,
            (int) $row['id'],
            (string) $row['filename'],
            in_array('webp', $formats, true) ? 'webp' : $formats[0],
        ));
    }

    /**
     * How long variant generation may run in this request, or null where PHP sets no
     * limit at all.
     *
     * Read here rather than inside MediaVariants because this is the request that has the
     * deadline; the generator is handed a number and does not care where it came from.
     * That separation is what lets the tests drive it with a budget of their choosing on
     * a machine whose max_execution_time is 0.
     */
    public static function budget(float $startedAt): ?float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            return null;
        }

        return max(1.0, $limit - (microtime(true) - $startedAt) - self::RESERVE_SECONDS);
    }

    /**
     * The chosen files as a plain list.
     *
     * PHP hands a multiple upload over as arrays inside ONE entry — name[0], tmp_name[0]
     * — rather than as a list of files, so every caller that does not flatten it reads
     * the first file and silently ignores the rest.
     *
     * @return list<array{name: string, tmp_name: string, error: int}>
     */
    private static function chosen(Request $request): array
    {
        $entry = $request->files['files'] ?? null;
        if (!is_array($entry) || !isset($entry['error'])) {
            return [];
        }

        $errors = is_array($entry['error']) ? $entry['error'] : [$entry['error']];
        $names = is_array($entry['name'] ?? null) ? $entry['name'] : [$entry['name'] ?? ''];
        $temporary = is_array($entry['tmp_name'] ?? null) ? $entry['tmp_name'] : [$entry['tmp_name'] ?? ''];

        $files = [];
        foreach (array_keys($errors) as $index) {
            $files[] = [
                'name' => (string) ($names[$index] ?? ''),
                'tmp_name' => (string) ($temporary[$index] ?? ''),
                'error' => (int) $errors[$index],
            ];
        }

        return $files;
    }

    private function library(): MediaLibrary
    {
        return $this->container->get('media_library');
    }
}
