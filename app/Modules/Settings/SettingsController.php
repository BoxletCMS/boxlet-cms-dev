<?php

namespace App\Modules\Settings;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Modules\Admin\AdminView;
use App\Modules\Media\MediaReference;
use App\Support\Url;
use DateTimeZone;

/**
 * Site settings (PLAN.md D-028): what the installer wrote, edited afterwards, plus the
 * pictures a site falls back to and the maintenance message.
 *
 * No new table. `settings` is `key` and value_json (migration 0003) and holds all of it
 * through App\Core\Settings, which is also what the installer and the admin shell use.
 *
 * The maintenance SWITCH is on this screen but posts to its own route, unchanged: the
 * flag is a file rather than a settings row on purpose (D-021 — maintenance is exactly
 * when the database may be unavailable), and giving it a second write path here would
 * have split one mechanism in two. Only the message lives in settings.
 */
final class SettingsController
{
    /** The keys this screen owns. site_name and timezone are the installer's, edited here. */
    private const PICTURES = ['site_logo', 'site_favicon', 'site_share_image'];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        return $this->form($this->stored(), [], null);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $values = [
            'site_name' => trim($request->input('site_name')),
            'timezone' => trim($request->input('timezone')),
            'maintenance_message' => trim($request->input('maintenance_message')),
        ];

        // The same list the installer checks against. Two screens writing one setting
        // against two different ideas of what is valid is how they end up disagreeing.
        if (!in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $values += $this->storedPictures();

            return $this->form($values, ['timezone' => t('settings.timezone_invalid')], null, 422);
        }

        // An id that names no picture becomes null, the rule MediaReference sets for block
        // content: nothing stops a library row being deleted after it was chosen here.
        $known = array_column(MediaReference::choices($db), 'id');
        $cleared = false;
        foreach (self::PICTURES as $key) {
            $chosen = (int) $request->input($key);
            if ($chosen > 0 && !in_array($chosen, $known, true)) {
                $chosen = 0;
                $cleared = true;
            }
            $values[$key] = $chosen > 0 ? $chosen : null;
        }

        foreach ($values as $key => $value) {
            Settings::set($db, $key, $value);
        }

        $this->container->get('session')->set(
            'flash',
            $cleared ? t('settings.saved') . ' ' . t('settings.picture_gone') : t('settings.saved'),
        );

        return Response::redirect(Url::admin('settings'));
    }

    /**
     * Everything the screen shows, in one query for the text and one for the pictures.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $db = $this->db();
        $text = Settings::many($db, ['site_name', 'timezone', 'maintenance_message'], '');

        return [
            'site_name' => is_string($text['site_name']) ? $text['site_name'] : '',
            'timezone' => is_string($text['timezone']) ? $text['timezone'] : '',
            'maintenance_message' => is_string($text['maintenance_message']) ? $text['maintenance_message'] : '',
        ] + $this->storedPictures();
    }

    /**
     * @return array<string, int|null>
     */
    private function storedPictures(): array
    {
        $db = $this->db();
        $pictures = [];
        foreach (self::PICTURES as $key) {
            $pictures[$key] = Settings::mediaId($db, $key);
        }

        return $pictures;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, ?string $notice, int $status = 200): Response
    {
        return AdminView::render($this->container, __DIR__ . '/views', 'settings', [
            'title' => t('settings.title'),
            'nav' => 'settings',
            // The picker's own stylesheets and script, the same set the page editor loads.
            'styles' => ['admin-media.css', 'admin-picker.css'],
            'scripts' => ['media-picker.js'],
            'values' => $values,
            'errors' => $errors,
            'notice' => $notice,
            'pictures' => MediaReference::choices($this->db()),
            'timezones' => DateTimeZone::listIdentifiers(),
            'maintenanceOn' => $this->container->get('maintenance')->isOn(),
        ], $status);
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
