<?php

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class DashboardController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Placeholder dashboard until later slices give the admin something to show.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, string $locale, array $params): Response
    {
        $row = $this->container->get('db')->one('SELECT value_json FROM settings WHERE `key` = ?', ['site_name']);
        $siteName = $row === null ? '' : json_decode((string) $row['value_json']);

        $html = (new View(__DIR__ . '/views'))->render('dashboard', 'en', [
            'title' => t('admin.dashboard.title'),
            'siteName' => is_string($siteName) ? $siteName : '',
            'csrf' => $this->container->get('session')->csrfToken(),
        ]);

        return Response::admin($html);
    }
}
