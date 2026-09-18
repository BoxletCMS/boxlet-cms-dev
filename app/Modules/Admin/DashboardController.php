<?php

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Design\Composition;

final class DashboardController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Where the site stands, at a glance, and the four things an owner comes here to do.
     *
     * Counts rather than lists: the screens behind each card already list everything, and
     * a dashboard that repeats them is a second place to keep in step. Four small queries,
     * each on an indexed or tiny table.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, string $locale, array $params): Response
    {
        // The maintenance switch used to live here. It moved to site settings (D-028), next
        // to the message it shows visitors, because the two were edited in two places and
        // only ever make sense together.
        $db = $this->db();
        $count = static fn (string $sql, array $bind = []): int => (int) ($db->one($sql, $bind)['n'] ?? 0);

        $home = $db->one("SELECT id FROM pages WHERE slug = '' ORDER BY id LIMIT 1");

        return AdminView::render($this->container, __DIR__ . '/views', 'dashboard', [
            'title' => t('admin.dashboard.title'),
            'nav' => 'dashboard',
            'styles' => ['admin-dashboard.css'],
            'published' => $count("SELECT COUNT(*) AS n FROM pages WHERE status = 'published'"),
            'drafts' => $count("SELECT COUNT(*) AS n FROM pages WHERE status <> 'published'"),
            'pictures' => $count('SELECT COUNT(*) AS n FROM media'),
            'menus' => $count('SELECT COUNT(*) AS n FROM menus'),
            'character' => Composition::active($db),
            'homeId' => $home === null ? null : (int) $home['id'],
            'maintenance' => $this->container->get('maintenance')->isOn(),
        ]);
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
