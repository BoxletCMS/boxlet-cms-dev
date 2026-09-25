<?php

namespace App\Modules\Media;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Pages\PageController;
use App\Modules\Stats\Bots;
use App\Support\Url;

/**
 * A file from the library, handed to a visitor to save (PLAN.md O-17, D-126).
 *
 * THROUGH PHP, NOT FROM A PUBLIC FOLDER, and that was the owner's choice between the two:
 * the file lives outside the web root beside the pictures' originals, so it is never served
 * as a page whatever it contains, and each download can be counted. The cost is PHP on
 * every download, which a small site does not notice; the file is streamed, never held.
 *
 * `/download/{id}/{name}`: the id finds the file, the name is for the person who sees the
 * address and the file they end up with. A new public address, added before v0.1 on
 * purpose (SPEC §5).
 */
final class DownloadController
{
    public function __construct(private readonly Container $container)
    {
    }

    /** Where a file is downloaded from. */
    public static function url(int $id, string $filename, string $extension): string
    {
        return Url::asset('download/' . $id . '/' . rawurlencode($filename) . ($extension === '' ? '' : '.' . $extension));
    }

    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $media = $db->one("SELECT * FROM media WHERE id = ? AND kind = 'file'", [(int) $params['id']]);
        $path = $media === null ? '' : (string) $this->container->get('config')->get('app.storage_path') . '/' . (string) $media['path'];
        // A picture's id asked for here is not a download, and a row whose file has gone is
        // not one either: both are the site's own "not found".
        if ($media === null || !is_file($path)) {
            return (new PageController($this->container))->notFound($request, $locale, $params);
        }

        /* COUNTED FOR A VISITOR, the rule the page statistics follow (Tracker::wanted()): not
           the admin — told by the session cookie, without starting a session — and not a
           crawler, which would otherwise count every file every night. */
        $isAdmin = preg_match('~(?:^|;)\s*boxlet_session=~', $request->header('cookie') ?? '') === 1;
        if ($request->method === 'GET' && !$isAdmin && !Bots::is($request->header('user-agent') ?? '')) {
            $db->query('UPDATE media SET downloads = downloads + 1 WHERE id = ?', [(int) $media['id']]);
        }

        $extension = strtolower(pathinfo((string) $media['path'], PATHINFO_EXTENSION));

        return Response::download($path, (string) $media['mime'], (string) $media['filename'] . '.' . $extension);
    }
}
