<?php

namespace App\Modules\Stats;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Support\Bytes;
use App\Support\Url;
use DateTimeImmutable;
use RuntimeException;

/**
 * The Statistics panel's actions (PLAN.md D-051): its settings, deleting every count, and
 * the country database. Switching the module off keeps what was counted; only erase
 * deletes it.
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

    /**
     * Fetches the country database from DB-IP (D-051): the one request statistics make to
     * anyone, made only when the owner presses the button.
     *
     * @param array<string, string> $params
     */
    public function geoDownload(Request $request, string $locale, array $params): Response
    {
        try {
            Geo::download($this->storage(), new DateTimeImmutable());
        } catch (RuntimeException $e) {
            return $this->back($e->getMessage(), true);
        }

        return $this->back(t('stats.geo_installed'));
    }

    /**
     * The country database uploaded by hand, for a server that cannot reach DB-IP: the
     * .mmdb file, or the .mmdb.gz DB-IP offers.
     *
     * @param array<string, string> $params
     */
    public function geoUpload(Request $request, string $locale, array $params): Response
    {
        $file = $request->files['geo_file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $temporary = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $this->back(t('stats.geo_upload_too_big', ['limit' => Bytes::limits()['fileLabel']]), true);
        }
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_uploaded_file($temporary)) {
            return $this->back(t('stats.geo_upload_none'), true);
        }
        try {
            Geo::install($this->storage(), $temporary);
        } catch (RuntimeException $e) {
            return $this->back($e->getMessage(), true);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $this->back(t('stats.geo_installed'));
    }

    private function storage(): string
    {
        return (string) $this->container->get('config')->get('app.storage_path');
    }

    private function back(string $message, bool $error = false): Response
    {
        $this->container->get('session')->set('flash', $message);
        if ($error) {
            $this->container->get('session')->set('flash_kind', 'error');
        }

        return Response::redirect(Url::admin('settings') . '#statistics');
    }
}
