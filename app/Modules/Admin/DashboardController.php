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
        return AdminView::render($this->container, __DIR__ . '/views', 'dashboard', [
            'title' => t('admin.dashboard.title'),
            'nav' => 'dashboard',
            // The maintenance switch lives here (D-021): the one screen the owner lands
            // on, and the one place that says which way round the site currently is.
            'maintenanceOn' => $this->container->get('maintenance')->isOn(),
        ]);
    }
}
