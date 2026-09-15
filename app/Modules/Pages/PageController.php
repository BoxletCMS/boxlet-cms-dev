<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class PageController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * TEMPORARY (Slice 1): a hard-coded page. Slice 3 replaces it with pages from the
     * database.
     *
     * @param array<string, string> $params
     */
    public function hello(Request $request, string $locale, array $params): Response
    {
        $copy = [
            'en' => ['title' => 'Hello', 'intro' => 'Boxlet is running.'],
            'hr' => ['title' => 'Bok', 'intro' => 'Boxlet radi.'],
        ];

        return $this->render('hello', $locale, $copy[$locale] ?? $copy['en']);
    }

    /**
     * @param array<string, string> $params
     */
    public function notFound(Request $request, string $locale, array $params): Response
    {
        $copy = [
            'en' => ['title' => 'Page not found', 'intro' => 'There is no page at this address.'],
            'hr' => ['title' => 'Stranica nije pronađena', 'intro' => 'Na ovoj adresi nema stranice.'],
        ];

        return $this->render('404', $locale, $copy[$locale] ?? $copy['en'], 404);
    }

    /**
     * @param array<string, string> $data
     */
    private function render(string $template, string $locale, array $data, int $status = 200): Response
    {
        /** @var View $view */
        $view = $this->container->get('view');
        $data['locales'] = $this->container->get('config')->get('locales.enabled');

        return Response::html($view->render($template, $locale, $data), $status);
    }
}
