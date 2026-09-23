<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Forms\FormBlocks;
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
            // The browser names the group the moment it places it (D-094), because only it
            // knows which keys the page is already using. This is the placeholder until then.
            'key' => BlockForm::key(null, 0),
            'id' => null,
            'type' => $type,
            'content' => $registry->fresh($type),
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
                    'key' => $first['key'],
                    'id' => null,
                    'type' => $first['type'],
                    'content' => $first['content'],
                    'style' => $first['style'],
                    'layout' => $first['layout'],
                ];
            }
        }

        /* AND THE BAND IT STANDS IN, LAST, OVER WHATEVER WAS WORKED OUT ABOVE (D-099).
         *
         * A block's style is its band's. A redraw that did not carry it drew the block with
         * whatever this method had composed instead — so one keystroke took a tinted, airy,
         * wide, centred band to plain, normal, narrow and left on the canvas, while the
         * database held the truth. The owner found that by opening the editor.
         *
         * Only when it is sent. A block arriving from the library has no band yet and keeps
         * the character's composition, which is what the library card showed; and a redraw
         * that sends no band is taken at its word — its own style, not a guess at one —
         * which is what the fidelity test below this file's endpoint asserts.
         */
        $band = $request->body['section'] ?? null;
        if (is_array($band) && isset($band['style'])) {
            $block['style'] = SectionStyle::normalize($band['style']);
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
            // What a form field offers: the forms of the page's own language (D-046).
            'formChoices' => \App\Modules\Forms\Form::choices($this->db(), (string) $page['locale']),
            'linkPages' => PageLinks::choices($this->db(), (string) $page['locale']),
            // The picture this block refers to, so a block re-drawn as it is edited shows
            // the photograph rather than the placeholder it had a moment ago.
            'canvasHtml' => $registry->render(
                $type,
                PageLinks::content($registry, $type, $block['content'], PageLinks::targets($this->db(), $registry, (string) $page['locale'], [$block])),
                $block['style'],
                $block['layout'],
                MediaPicture::forBlocks($this->db(), $registry, $locale, [$block]),
                false,
                /* A BLOCK GOING INTO A COLUMN IS NOT A BAND (PLAN.md D-099). The band
                   around it already draws the surface, the rhythm and the container, so
                   what comes back is the block's own wrapper and nothing else — exactly
                   what SectionRender::draw() renders for a section holding more than one.
                   Asked for by the presence of a column, which is the only thing the
                   browser knows at the moment it presses a + in an empty one. */
                $request->input('column') === '' ? 'section' : 'none',
                // The form this block shows, drawn as a visitor sees it (D-046).
                ['forms' => FormBlocks::resolve($this->db(), [$block], (string) $page['locale'], null, (string) $this->container->get('config')->get('app.key'))],
                (string) $page['locale'],
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
