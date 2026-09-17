<?php

namespace App\Modules\Media;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Support\Url;
use Throwable;

/**
 * Admin: one picture — what it means, what stays in frame when it is cropped, and
 * removing it.
 *
 * Alt text and caption are per locale, because a picture means the same thing in two
 * languages but is described in the reader's. An EMPTY alt is a decision rather than an
 * unfilled field: it says the picture is decoration and a screen reader should pass over
 * it. That is why it is stored as an empty string instead of being left absent.
 */
final class MediaItemController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        $library = $this->library();
        $id = (int) $params['id'];
        $media = $library->find($id);
        if ($media === null) {
            return MediaController::missing();
        }

        return AdminView::render($this->container, __DIR__ . '/views', 'admin/show', [
            'title' => (string) $media['filename'],
            'nav' => 'media',
            'styles' => ['admin-media.css'],
            'scripts' => ['media.js'],
            'picture' => MediaController::card($media),
            // The uncropped variant, and only that one — see below.
            'preview' => MediaController::variant($media, 'full'),
            'focal' => ['x' => (int) $media['focal_x'], 'y' => (int) $media['focal_y']],
            'meta' => $library->meta($id),
            'locales' => $this->container->get('locales'),
            'usedBy' => $library->usedBy($id),
            'added' => (string) $media['created_at'],
            'mime' => (string) $media['mime'],
        ]);
    }

    /**
     * Saves alt text and caption for every enabled locale in one submit, because they sit
     * on one screen and a per-locale save button would be four buttons doing one job.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $library = $this->library();
        $id = (int) $params['id'];
        if ($library->find($id) === null) {
            return MediaController::missing();
        }

        foreach ($this->container->get('locales') as $enabled) {
            $code = (string) $enabled['code'];
            $library->saveMeta($id, $code, trim($request->input('alt_' . $code)), trim($request->input('caption_' . $code)));
        }
        $this->container->get('session')->set('flash', t('media.meta_saved'));

        return Response::redirect(Url::admin('media', $id));
    }

    /**
     * Moves the point every crop keeps in frame.
     *
     * THE POINT IS CHOSEN ON THE UNCROPPED PICTURE. `full` is the only variant that is
     * not cropped (SPEC §5.5), so it is the only one where a click means what it looks
     * like it means: on a cropped preview the edges are already gone, and a point chosen
     * near one would land somewhere else entirely once applied to the source.
     *
     * Setting it clears the variants, so they are made again here — otherwise the picture
     * would have no crops at all until someone pressed Finish, and the screen that just
     * said "saved" would show a broken thumbnail.
     *
     * @param array<string, string> $params
     */
    public function focal(Request $request, string $locale, array $params): Response
    {
        $library = $this->library();
        $id = (int) $params['id'];
        if ($library->find($id) === null) {
            return MediaController::missing();
        }

        $library->setFocalPoint($id, (int) $request->input('x'), (int) $request->input('y'));
        $this->container->get('media_variants')->generate($id, MediaController::budget(microtime(true)));
        $this->container->get('session')->set('flash', t('media.focal_saved'));

        return Response::redirect(Url::admin('media', $id));
    }

    /**
     * Puts different bytes behind this picture, keeping its id.
     *
     * Nothing is removed until the new file has been accepted and written, so a refused
     * replacement leaves the picture exactly as it was — which is why the uploader hands
     * back the row as it stood rather than simply reporting success.
     *
     * @param array<string, string> $params
     */
    public function replace(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->library()->find($id) === null) {
            return MediaController::missing();
        }

        $file = $request->files['file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $problem = MediaUpload::problem($error);
        if ($problem !== null) {
            return $this->saying($problem, $id);
        }

        try {
            $was = $this->container->get('media_upload')->replace(
                $id,
                (string) ($file['tmp_name'] ?? ''),
                (string) ($file['name'] ?? ''),
            );
        } catch (Throwable $e) {
            return $this->saying($e->getMessage(), $id);
        }

        // The old files are of a picture that is no longer at this id.
        $this->library()->forgetVariants($was);
        $this->container->get('media_variants')->generate($id, MediaController::budget(microtime(true)));

        return $this->saying(t('media.replaced'), $id);
    }

    /**
     * Removes a picture, unless a page still shows it — in which case the refusal names
     * the pages, because a refusal that does not sends someone hunting through the site.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        $result = $this->library()->delete($id);

        // delete() reports the same "not deleted, nothing using it" for a row that was
        // never there, which is the one case that is a 404 rather than a refusal.
        if (!$result['deleted'] && $result['used_by'] === []) {
            return MediaController::missing();
        }

        if (!$result['deleted']) {
            $this->container->get('session')->set('flash', t('media.in_use', ['pages' => implode(', ', $result['used_by'])]));

            return Response::redirect(Url::admin('media', $id));
        }

        $this->container->get('session')->set('flash', t('media.deleted'));

        return Response::redirect(Url::admin('media'));
    }

    /**
     * Back to the picture, with something to say about what just happened.
     */
    private function saying(string $message, int $mediaId): Response
    {
        $this->container->get('session')->set('flash', $message);

        return Response::redirect(Url::admin('media', $mediaId));
    }

    private function library(): MediaLibrary
    {
        return $this->container->get('media_library');
    }
}
