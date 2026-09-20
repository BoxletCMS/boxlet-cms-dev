<?php

namespace App\Modules\Stats;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Support\Bytes;
use App\Support\Dates;
use App\Support\Url;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Taking the counts out and putting them back (PLAN.md O-20): a CSV of one table as the
 * screen shows it, a JSON file of everything, and the import that reads one back.
 */
final class StatsDataController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function export(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $settings = Tracker::settings($db);
        if (!$settings['enabled']) {
            return Response::redirect(Url::admin('settings') . '#statistics');
        }

        $today = new DateTimeImmutable('now', new DateTimeZone(Dates::zone($db)));
        $filter = StatsFilter::fromQuery($request->query, $today);
        $table = is_string($request->query['table'] ?? null) ? $request->query['table'] : '';

        if ($table === 'everything') {
            return self::file(StatsExport::json($db), 'application/json', 'boxlet-statistics-' . $today->format('Y-m-d') . '.json');
        }
        if (!isset(StatsQuery::DIMENSIONS[$table]) && $table !== 'missing') {
            return Response::redirect(Url::admin('statistics'));
        }

        return self::file(
            StatsExport::csv($db, $table, $filter, $settings['group']),
            'text/csv; charset=utf-8',
            sprintf('boxlet-%s-%s-%s.csv', $table, $filter->from, $filter->to),
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function import(Request $request, string $locale, array $params): Response
    {
        $file = $request->files['stats_file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $temporary = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        $session = $this->container->get('session');

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $this->back(t('stats.import_too_big', ['limit' => Bytes::limits()['fileLabel']]), true);
        }
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_uploaded_file($temporary)) {
            return $this->back(t('stats.import_none'), true);
        }

        $result = StatsExport::import($this->container->get('db'), $temporary);
        if (is_file($temporary)) {
            unlink($temporary);
        }
        if ($result['error'] !== '') {
            return $this->back($result['error'], true);
        }
        $session->set('flash', t('stats.import_done', ['rows' => number_format($result['rows'])]));

        return Response::redirect(Url::admin('statistics'));
    }

    private static function file(string $body, string $type, string $name): Response
    {
        return new Response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function back(string $message, bool $error): Response
    {
        $session = $this->container->get('session');
        $session->set('flash', $message);
        if ($error) {
            $session->set('flash_kind', 'error');
        }

        return Response::redirect(Url::admin('statistics'));
    }
}
