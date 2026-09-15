<?php

namespace App\Modules\Design;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Admin\AdminView;
use App\Modules\Pages\Page;
use App\Support\Url;

/**
 * Admin Design screen: characters (layer 0), the eight decisions (layer 1), and a live
 * preview.
 *
 * Saving is an ordinary form post that works without JavaScript. Applying a character
 * to a site that already has blocks offers two explicit buttons — the design alone, or
 * the design with its composition — because the second overwrites section styles the
 * user may have chosen by hand (SPEC §5.4).
 */
final class DesignController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        return $this->form(Design::load($this->db()), [], null);
    }

    /**
     * Save, or load a character into the form. A loaded character changes nothing on the
     * site until the form is saved: Save is the confirmation.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $action = $request->input('action');
        if (str_starts_with($action, 'preset:') && Presets::exists(substr($action, 7))) {
            $name = substr($action, 7);

            return $this->form(
                Presets::get($name),
                [],
                t('design.preset_loaded', ['preset' => t('design.preset.' . $name)]),
                200,
                $name,
            );
        }

        $character = $request->input('character');
        $character = Presets::exists($character) ? $character : '';
        $result = Tokens::validate(self::submitted($request->body));
        if ($result['errors'] !== []) {
            return $this->form($result['decisions'], $result['errors'], t('design.not_saved'), 422, $character);
        }

        $db = $this->db();
        Design::save($db, $result['decisions'], (string) $this->container->get('config')->get('app.cache_path'));
        $message = t('design.saved');
        if ($character !== '') {
            Composition::remember($db, $character);
            if ($action === 'save_composition') {
                $count = Composition::apply($db, $this->container->get('blocks'), $character);
                $message = t('design.saved_with_composition', [
                    'count' => $count,
                    'character' => t('design.preset.' . $character),
                ]);
            }
        }
        $this->container->get('session')->set('flash', $message);

        return Response::redirect(Url::admin('design'));
    }

    /**
     * The site rendered with the submitted values, for the iframe on the Design screen:
     * the primary home page if there is one, else a specimen of every surface. With a
     * character named, blocks are composed as that character composes them, so the
     * preview shows the shape the page would take and not only its colours.
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, string $locale, array $params): Response
    {
        $decisions = $this->previewDecisions($request->query);
        Url::useStylesheet(Url::withQuery(Url::admin('design', 'stylesheet'), self::query($decisions)));

        $character = is_string($request->query['character'] ?? null) ? $request->query['character'] : '';
        $character = Presets::exists($character) ? $character : null;
        $registry = $this->container->get('blocks');
        $home = ($request->query['specimen'] ?? '') === '1' ? null : $this->db()->one(
            'SELECT p.id FROM pages p JOIN locales l ON l.code = p.locale WHERE p.slug = ? AND l.is_primary = 1',
            [''],
        );

        $blocks = [];
        if ($home !== null) {
            foreach (Page::blocks($this->db(), (int) $home['id']) as $block) {
                if ($registry->has($block['type'])) {
                    $blocks[] = [$block['type'], $block['content'], $block['style'], $block['layout']];
                }
            }
        } else {
            $blocks = self::specimen();
        }

        $html = '';
        foreach ($blocks as [$type, $content, $style, $layout]) {
            if ($character !== null) {
                $style = Composition::style($character, $type);
                $layout = Composition::layout($registry, $character, $type);
            }
            $html .= $registry->render($type, $content, $style, $layout);
        }

        $view = new View(dirname(__DIR__) . '/Pages/views');
        $body = $view->render('page', 'en', ['title' => t('design.preview'), 'blocksHtml' => $html, 'canonical' => null, 'locales' => []]);
        $response = Response::admin($body);
        // The one admin page that may be framed, and only by the admin itself.
        $response->headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data:; form-action 'none'; frame-ancestors 'self'; base-uri 'none'";
        $response->headers['X-Frame-Options'] = 'SAMEORIGIN';

        return $response;
    }

    /**
     * @param array<string, string> $params
     */
    public function previewCss(Request $request, string $locale, array $params): Response
    {
        $decisions = $this->previewDecisions($request->query);
        $fonts = Typography::fontFaces($decisions['typography'], Url::asset('assets/fonts'));
        $css = (new TokenCompiler())->css(Tokens::derive($decisions), $fonts);

        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Contrast errors keyed by decision and the derived palette, for the Design screen to
     * show inline while values change. The same validation Save runs.
     *
     * @param array<string, string> $params
     */
    public function check(Request $request, string $locale, array $params): Response
    {
        $result = Tokens::validate(self::submitted($request->query));
        $decisions = $result['decisions'];
        $body = json_encode([
            'errors' => (object) $result['errors'],
            'colors' => Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']),
        ], JSON_THROW_ON_ERROR);

        return new Response($body, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    /**
     * Form fields as decisions: the second colour counts only when its checkbox is on,
     * because a colour input always submits some colour.
     *
     * @param array<mixed> $fields
     * @return array<mixed>
     */
    private static function submitted(array $fields): array
    {
        if (($fields['use_secondary'] ?? '') !== '1') {
            $fields['secondary'] = '';
        }

        return $fields;
    }

    /**
     * @param array<string, string> $decisions
     * @return array<string, string> the query string the preview endpoints read back
     */
    private static function query(array $decisions): array
    {
        return $decisions + ['use_secondary' => $decisions['secondary'] !== '' ? '1' : '0'];
    }

    /**
     * @param array<mixed> $query
     * @return array<string, string>
     */
    private function previewDecisions(array $query): array
    {
        $preset = $query['preset'] ?? null;
        if (is_string($preset) && Presets::exists($preset)) {
            return Presets::get($preset);
        }
        if (!isset($query['seed'])) {
            return Design::load($this->db());
        }

        return Tokens::validate(self::submitted($query))['decisions'];
    }

    /**
     * @return list<array{string, array<string, mixed>, array<string, string>, string}>
     */
    private static function specimen(): array
    {
        $paragraph = static fn (string $key): string => '<p>' . e(t($key)) . ' <a href="#">' . e(t('design.specimen.link')) . '</a>.</p>';

        return [
            ['hero', ['heading' => t('design.specimen.hero'), 'subheading' => t('design.specimen.hero_sub'), 'cta' => ['label' => t('design.specimen.button'), 'url' => '#']], ['surface' => 'gradient', 'rhythm' => 'airy', 'align' => 'center'], 'center'],
            ['text', ['heading' => t('design.specimen.text_heading'), 'body' => $paragraph('design.specimen.text_body')], [], 'single'],
            ['image_text', ['heading' => t('design.specimen.tinted_heading'), 'body' => $paragraph('design.specimen.tinted_body'), 'image' => 1, 'link' => ['label' => t('design.specimen.button'), 'url' => '#']], ['surface' => 'tinted', 'divider' => 'line'], 'image-left'],
            ['hero', ['heading' => t('design.specimen.contrast_heading'), 'subheading' => t('design.specimen.contrast_body'), 'cta' => ['label' => t('design.specimen.button'), 'url' => '#']], ['surface' => 'contrast', 'divider' => 'slant'], 'left'],
        ];
    }

    /**
     * @param array<string, string> $decisions
     * @param array<string, string> $errors
     * @param string                $character the character loaded into the form, if any
     */
    private function form(array $decisions, array $errors, ?string $notice, int $status = 200, string $character = ''): Response
    {
        $db = $this->db();
        $previewQuery = self::query($decisions);
        if ($character !== '') {
            $previewQuery['character'] = $character;
        }

        return AdminView::render($this->container, __DIR__ . '/views', 'design', [
            'title' => t('design.title'),
            'nav' => 'design',
            'styles' => ['admin-design.css'],
            'wide' => true,
            'decisions' => $decisions,
            'errors' => $errors,
            'notice' => $notice,
            'character' => $character,
            'activeCharacter' => Composition::active($db),
            'hasBlocks' => Composition::hasBlocks($db),
            'colors' => Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']),
            'derived' => Tokens::derive($decisions),
            'previewUrl' => Url::withQuery(Url::admin('design', 'preview'), $previewQuery),
        ], $status);
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
