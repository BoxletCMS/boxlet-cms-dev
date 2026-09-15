<?php

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Response;
use App\Core\View;

/**
 * Renders an admin page inside the admin shell (Admin/views/layout.php): site name,
 * navigation, CSRF token for the logout form and a one-time flash message.
 */
final class AdminView
{
    /**
     * @param string               $directory the module's views directory
     * @param array<string, mixed> $data
     */
    public static function render(Container $container, string $directory, string $template, array $data, int $status = 200): Response
    {
        $session = $container->get('session');
        $row = $container->get('db')->one('SELECT value_json FROM settings WHERE `key` = ?', ['site_name']);
        $siteName = $row === null ? '' : json_decode((string) $row['value_json']);
        $flash = $session->get('flash');
        $session->remove('flash');

        $data += [
            'title' => t('admin.brand'),
            'nav' => '',
            'siteName' => is_string($siteName) ? $siteName : '',
            'flash' => is_string($flash) ? $flash : null,
            'csrf' => $session->csrfToken(),
        ];
        $html = (new View($directory, __DIR__ . '/views'))->render($template, 'en', $data);

        return Response::admin($html, $status);
    }
}
