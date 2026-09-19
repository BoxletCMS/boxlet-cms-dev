<?php

namespace App\Modules\Stats;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Support\Url;

/**
 * The Statistics panel's two actions (PLAN.md D-051): its settings, and deleting every
 * count. Switching the module off keeps what was counted; only erase deletes it.
 */
final class StatsSettingsController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $retention = (int) $request->input('stats_retention');
        Settings::set($db, 'stats_enabled', $request->input('stats_enabled') === '1');
        Settings::set($db, 'stats_dnt', $request->input('stats_dnt') === '1');
        Settings::set($db, 'stats_retention', in_array($retention, Tracker::RETENTION, true) ? $retention : Tracker::settings($db)['retention']);

        return $this->back(t('stats.saved'));
    }

    /**
     * @param array<string, string> $params
     */
    public function erase(Request $request, string $locale, array $params): Response
    {
        Tracker::erase($this->container->get('db'));

        return $this->back(t('stats.erased'));
    }

    private function back(string $message): Response
    {
        $this->container->get('session')->set('flash', $message);

        return Response::redirect(Url::admin('settings') . '#statistics');
    }
}
