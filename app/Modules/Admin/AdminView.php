<?php

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Response;
use App\Core\Settings;
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
        $siteName = Settings::text($container->get('db'), 'site_name');
        $flash = $session->get('flash');
        // A refusal dressed as a confirmation is worse than no message: the owner reads the
        // colour before the words. One slot, two kinds — 'success' unless a controller says
        // otherwise, so every existing caller keeps the message it already sets.
        $kind = $session->get('flash_kind');
        $session->remove('flash');
        $session->remove('flash_kind');

        $data += [
            'title' => t('admin.brand'),
            'nav' => '',
            // Stylesheets this screen needs on top of the shell's own, and whether it
            // wants the wide column (the Design screen does; a form does not).
            'styles' => [],
            // Scripts this screen needs beyond admin.js, in load order. Deferred, so they
            // run in the order they are listed.
            'scripts' => [],
            'wide' => false,
            // A screen that fills the window itself rather than sitting in the reading
            // column: the visual editor, whose canvas is the screen.
            'bare' => false,
            'siteName' => $siteName,
            'flash' => is_string($flash) ? $flash : null,
            // 'error' as well: five controllers set it for a refusal, and until D-051 found
            // it here it was drawn as 'success' — the very thing the comment above forbids.
            'flashKind' => in_array($kind, ['warning', 'error'], true) ? $kind : 'success',
            'csrf' => $session->csrfToken(),
            // Statistics has a place in the bar only while it counts (D-051).
            'statsOn' => \App\Modules\Stats\Tracker::settings($container->get('db'))['enabled'],
        ];
        $html = (new View($directory, __DIR__ . '/views'))->render($template, 'en', $data);

        return Response::admin($html, $status);
    }
}
