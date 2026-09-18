<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Design\Composition;
use App\Modules\Media\MediaPicture;
use App\Modules\Media\MediaReference;

/**
 * One block, as the fragments the visual editor asks for.
 *
 * Split out of PageBuilderController, which had reached the 300-line limit. The seam is a
 * real one rather than a convenience: PageBuilderController renders the EDITOR — its
 * shell, its canvas document, and the re-render after a refused save — while this answers
 * a single endpoint that draws one block, either brand new or as it is being edited. It
 * writes nothing.
 *
 * Moved unchanged. The route, the response and the parsing are exactly what they were.
 */
final class PageBlockController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * One new block, as two fragments: the section for the canvas and the field group for
     * the form. Nothing is written — the block exists only in the page being edited until
     * Save, exactly like a block added in the fallback editor.
     *
     * The response is HTML, not JSON. The server owns what a block is; sending a schema
     * for the browser to render would put a second copy of the block definition in
     * JavaScript, which is the thing this design exists to avoid.
     *
     * @param array<string, string> $params
     */
    public function insert(Request $request, string $locale, array $params): Response
    {
        $page = Page::find($this->db(), (int) $params['id']);
        if ($page === null) {
            return PagesController::missing();
        }

        $registry = $this->registry();
        $type = $request->input('type');
        if (!$registry->has($type)) {
            return new Response(t('pages.insert_unknown'), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $character = Composition::active($this->db());
        $block = [
            'id' => null,
            'type' => $type,
            'content' => $registry->normalize($type, []),
            'style' => Composition::style($character, $type),
            'layout' => Composition::layout($registry, $character, $type),
        ];

        // With values posted, this is a block being re-drawn as it is edited rather than
        // a new one. The same parser the save runs cleans them, so the canvas shows what
        // would actually be stored — including rich text reduced to the whitelist.
        $submitted = $request->body['block'] ?? null;
        if (is_array($submitted)) {
            $parsed = BlockForm::parse($registry, [['type' => $type] + $submitted], []);
            $first = $parsed['blocks'][0] ?? null;
            // A parsed block keeps a null content when its type is not installed, which
            // this method has already ruled out; building the block explicitly says so
            // rather than carrying the null through.
            if ($first !== null && is_array($first['content'])) {
                $block = [
                    'id' => null,
                    'type' => $first['type'],
                    'content' => $first['content'],
                    'style' => $first['style'],
                    'layout' => $first['layout'],
                ];
            }
        }

        $body = (new View(__DIR__ . '/views'))->render('admin/insert', $locale, [
            // The browser renumbers every group after inserting, so this index only has
            // to be unique in the returned markup.
            'index' => max(0, (int) $request->input('index')),
            'block' => $block,
            'errors' => [],
            'character' => $character,
            'registry' => $registry,
            'pictures' => MediaReference::choices($this->db()),
            'linkPages' => PageLinks::choices($this->db(), (string) $page['locale']),
            // The picture this block refers to, so a block re-drawn as it is edited shows
            // the photograph rather than the placeholder it had a moment ago.
            'canvasHtml' => $registry->render(
                $type,
                PageLinks::content($registry, $type, $block['content'], PageLinks::targets($this->db(), $registry, (string) $page['locale'], [$block])),
                $block['style'],
                $block['layout'],
                MediaPicture::forBlocks($this->db(), $registry, $locale, [$block]),
            ),
        ], null);

        return Response::admin($body);
    }

    private function registry(): Blocks
    {
        return $this->container->get('blocks');
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
