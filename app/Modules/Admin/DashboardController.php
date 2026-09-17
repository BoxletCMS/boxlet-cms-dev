<?php

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

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
        // The maintenance switch used to live here. It moved to site settings (D-028), next
        // to the message it shows visitors, because the two were edited in two places and
        // only ever make sense together.
        return AdminView::render($this->container, __DIR__ . '/views', 'dashboard', [
            'title' => t('admin.dashboard.title'),
            'nav' => 'dashboard',
        ]);
    }
}
