<?php

use App\Core\Blocks;
use App\Core\Config;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Modules\Admin\ActivityController;
use App\Modules\Admin\AppearanceController;
use App\Modules\Admin\DashboardController;
use App\Modules\Admin\RequireAdmin;
use App\Modules\Admin\SearchController;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\TwoFactorController;
use App\Modules\Design\Design;
use App\Modules\Design\DesignController;
use App\Modules\Forms\FormsController;
use App\Modules\Forms\FormSubmitController;
use App\Modules\Forms\MessagesController;
use App\Modules\Languages\LanguagesController;
use App\Modules\Mailer\MailController;
use App\Modules\Mailer\MailSettings;
use App\Modules\Media\MediaController;
use App\Modules\Media\MediaCropController;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaItemController;
use App\Modules\Media\MediaLibrary;
use App\Modules\Media\MediaRemake;
use App\Modules\Media\MediaRemakeController;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;
use App\Modules\Menus\MenusController;
use App\Modules\Pages\PageBlockController;
use App\Modules\Pages\PageBuilderController;
use App\Modules\Pages\PageController;
use App\Modules\Pages\PageEditorController;
use App\Modules\Pages\PagesController;
use App\Modules\Pages\TranslationController;
use App\Modules\Settings\ChromeController;
use App\Modules\Settings\SettingsController;
use App\Modules\Stats\StatsController;
use App\Modules\Stats\StatsDataController;
use App\Modules\Stats\StatsSettingsController;
use App\Modules\Update\Maintenance;
use App\Modules\Update\MaintenanceController;
use App\Modules\Update\Update;
use App\Modules\Update\UpdateController;
use App\Support\Url;

/**
 * Builds the container for the current request and registers routes. Expects
 * vendor/autoload.php and .env to be loaded already. Shared by public/index.php and
 * tests/support.php, so tests dispatch exactly as production does.
 */

$root = dirname(__DIR__);
$config = new Config($root . '/config');
$storage = (string) $config->get('app.storage_path');
$cache = (string) $config->get('app.cache_path');

// Loaded eagerly: a malformed block definition must fail at boot, not at render.
$blocks = Blocks::discover($root . '/app/Blocks');

// The site's header and footer: the SAME machine over a different directory (PLAN.md
// D-030), so they inherit the design tokens and the section style layers. Two registries
// rather than one directory with a scope flag, because the page editor's library IS
// $blocks — so a header cannot be offered as page content by construction, rather than by
// a check somebody has to remember. Eager for the same reason as above.
$chrome = Blocks::discover($root . '/app/Chrome');

$request = Request::fromGlobals();
Url::configure($request->basePath, '');
Url::usePublicPath($root . '/public');

$container = new Container();
$container->set('config', fn () => $config);
$container->set('request', fn () => $request);
$container->set('blocks', fn () => $blocks);
$container->set('chrome', fn () => $chrome);
$container->set('installed', fn () => is_file($storage . '/install.lock'));
$container->set('db', fn () => Db::fromConfig($config->get('database', [])));
$container->set('session', fn () => Session::start($storage . '/sessions', $request->https));
$container->set('locales', fn (Container $c) => $c->get('db')->all(
    'SELECT code, label, is_primary FROM locales WHERE enabled = 1 ORDER BY sort, code'
));
// Updating an existing install (D-019). The SQLite path is passed in rather than read
// back out of the connection: only MySQL sites have nothing to copy.
// Fetched as ['path'] rather than by the dotted key 'database.path': a guard scrapes
// this file for ->get('...') and ->post('...') and rejects any that ends in a file
// extension, because managed nginx answers such a URL from disk and never passes the
// miss to PHP. The dotted key is not a route, but it is indistinguishable from one here.
$databasePath = (string) (($config->get('database', []))['path'] ?? '');
// Maintenance mode (D-021): a file, not a settings row, so it still works when the
// database is unavailable or mid-update.
$container->set('maintenance', fn () => new Maintenance($storage));
// How the site sends mail (D-045), built from its settings when first asked for. Tests
// replace it with one that keeps what it is given.
// The key is fetched as ['key'] rather than by the dotted 'app.key', for the reason
// $databasePath gives above: the route guard reads any get('...') ending in an extension.
$container->set('mail_transport', fn (Container $c) => MailSettings::transport($c->get('db'), (string) (($c->get('config')->get('app', []))['key'] ?? '')));
// Media (SPEC §5.5). The encoder probes what this server can actually write, so a host
// without a WebP delegate refuses clearly instead of writing files nobody can open.
$container->set('media_encoder', fn () => new MediaEncoder());
$container->set('media_writer', fn (Container $c) => new MediaWriter($c->get('media_encoder')));
$container->set('media_upload', fn (Container $c) => new MediaUpload($c->get('db'), $storage, $c->get('media_encoder')));
// Making every picture's sizes again, step by step (D-048).
$container->set('media_remake', fn (Container $c) => new MediaRemake(
    $c->get('db'),
    $c->get('media_encoder'),
    $c->get('media_writer'),
    $c->get('media_variants'),
    $storage,
    $root . '/public',
));
$container->set('media_variants', fn (Container $c) => new MediaVariants(
    $c->get('db'),
    $c->get('media_encoder'),
    $c->get('media_writer'),
    $storage,
    $root . '/public',
));
// The library needs the block registry as well as the database: which fields can hold a
// picture is declared by the blocks, so a block added later is covered without editing it.
$container->set('media_library', fn (Container $c) => new MediaLibrary(
    $c->get('db'),
    $c->get('blocks'),
    $storage,
    $root . '/public',
));
$container->set('update', fn (Container $c) => new Update(
    $c->get('db'),
    $root . '/migrations',
    $storage,
    $c->get('db')->driver === 'sqlite' ? $databasePath : null,
));

$container->set('router', function (Container $c) use ($request, $cache): Router {
    $locales = $c->get('locales');
    $primary = '';
    foreach ($locales as $locale) {
        if ((int) $locale['is_primary'] === 1) {
            $primary = (string) $locale['code'];
        }
    }
    $origin = Url::origin($request->https, (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'), (int) ($_SERVER['SERVER_PORT'] ?? 0));
    Url::configure($request->basePath, $primary, $origin);
    // The compiled design stylesheet; its hashed name changes whenever the design is saved.
    Url::useStylesheet(Url::asset('cache/' . Design::stylesheet($c->get('db'), $cache)));
    $router = new Router($c, array_column($locales, 'code'), $primary);

    // Admin routes never carry a locale prefix (SPEC §5.1). They are registered before
    // the page route, whose variable pattern would otherwise shadow them.
    $requireAdmin = [[RequireAdmin::class, 'handle']];
    $router->get('/admin/login', [AuthController::class, 'showLogin']);
    $router->post('/admin/login', [AuthController::class, 'login']);
    // The second step of logging in, when two-step login is on (D-050).
    $router->get('/admin/login/code', [AuthController::class, 'showCode']);
    $router->post('/admin/login/code', [AuthController::class, 'code']);
    $router->post('/admin/logout', [AuthController::class, 'logout'], $requireAdmin);
    $router->get('/admin', [DashboardController::class, 'index'], $requireAdmin);
    // The only route that applies a migration, and the only admin screen the update gate
    // lets through while one is pending (D-019).
    $router->get('/admin/update', [UpdateController::class, 'show'], $requireAdmin);
    $router->post('/admin/update', [UpdateController::class, 'run'], $requireAdmin);
    // Maintenance mode (D-021). The GET is where the bar's link goes; it only brings the
    // owner back to the dashboard, because switching off is a POST with a token.
    $router->get('/admin/maintenance', [MaintenanceController::class, 'show'], $requireAdmin);
    $router->post('/admin/maintenance', [MaintenanceController::class, 'toggle'], $requireAdmin);
    $router->get('/admin/pages', [PagesController::class, 'index'], $requireAdmin);
    $router->get('/admin/pages/new', [PagesController::class, 'create'], $requireAdmin);
    $router->post('/admin/pages', [PagesController::class, 'store'], $requireAdmin);
    // The visual editor is the page editor; the plain form stays reachable as the
    // fallback for a browser without JavaScript or a canvas that will not load.
    $router->get('/admin/pages/{id:\d+}', [PageBuilderController::class, 'edit'], $requireAdmin);
    $router->get('/admin/pages/{id:\d+}/canvas', [PageBuilderController::class, 'canvas'], $requireAdmin);
    // One block, drawn for the canvas and the panel. Its own controller since 4c: the
    // builder renders the editor, this answers for a single block and writes nothing.
    $router->post('/admin/pages/{id:\d+}/block', [PageBlockController::class, 'insert'], $requireAdmin);
    $router->get('/admin/pages/{id:\d+}/form', [PageEditorController::class, 'edit'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}', [PageEditorController::class, 'update'], $requireAdmin);
    $router->post('/admin/pages/order', [PagesController::class, 'reorder'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/status', [PagesController::class, 'status'], $requireAdmin);
    // A page's version in another language, made as a draft copy (D-043).
    $router->post('/admin/pages/{id:\d+}/translate', [TranslationController::class, 'create'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/blocks/{block:\d+}/current', [TranslationController::class, 'current'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/delete', [PagesController::class, 'delete'], $requireAdmin);

    // Pictures (SPEC §5.5). The generated variants live under /m/ and are served from
    // disk by the web server; no route here ever answers for one.
    $router->get('/admin/media', [MediaController::class, 'index'], $requireAdmin);
    $router->post('/admin/media', [MediaController::class, 'store'], $requireAdmin);
    // Making every picture's sizes again, a step per request (D-048).
    $router->post('/admin/media/remake', [MediaRemakeController::class, 'start'], $requireAdmin);
    $router->post('/admin/media/remake/step', [MediaRemakeController::class, 'step'], $requireAdmin);
    $router->get('/admin/media/{id:\d+}', [MediaItemController::class, 'show'], $requireAdmin);
    $router->post('/admin/media/{id:\d+}', [MediaItemController::class, 'save'], $requireAdmin);
    $router->post('/admin/media/{id:\d+}/crop', [MediaCropController::class, 'crop'], $requireAdmin);
    $router->post('/admin/media/{id:\d+}/replace', [MediaItemController::class, 'replace'], $requireAdmin);
    $router->post('/admin/media/{id:\d+}/delete', [MediaItemController::class, 'delete'], $requireAdmin);
    $router->post('/admin/media/{id:\d+}/finish', [MediaController::class, 'finish'], $requireAdmin);

    // Menus (D-028, resolving O-7). One ordering route takes both paths, D-011: a drag
    // posts a whole sibling order, a button posts one move.
    $router->get('/admin/menus', [MenusController::class, 'index'], $requireAdmin);
    $router->post('/admin/menus', [MenusController::class, 'store'], $requireAdmin);
    $router->get('/admin/menus/{id:\d+}', [MenusController::class, 'edit'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/rename', [MenusController::class, 'rename'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/delete', [MenusController::class, 'delete'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/items', [MenusController::class, 'addItem'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/items/{item:\d+}', [MenusController::class, 'updateItem'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/items/{item:\d+}/delete', [MenusController::class, 'deleteItem'], $requireAdmin);
    $router->post('/admin/menus/{id:\d+}/order', [MenusController::class, 'order'], $requireAdmin);

    // Forms (D-046): the list, a new one, and its edit screen, where every button saves.
    $router->get('/admin/forms', [FormsController::class, 'index'], $requireAdmin);
    $router->post('/admin/forms', [FormsController::class, 'store'], $requireAdmin);
    $router->get('/admin/forms/{id:\d+}', [FormsController::class, 'edit'], $requireAdmin);
    $router->post('/admin/forms/{id:\d+}', [FormsController::class, 'update'], $requireAdmin);
    $router->post('/admin/forms/{id:\d+}/delete', [FormsController::class, 'delete'], $requireAdmin);
    $router->get('/admin/forms/{id:\d+}/messages', [MessagesController::class, 'index'], $requireAdmin);
    $router->get('/admin/forms/{id:\d+}/messages/{message:\d+}', [MessagesController::class, 'show'], $requireAdmin);
    $router->post('/admin/forms/{id:\d+}/messages/{message:\d+}/delete', [MessagesController::class, 'delete'], $requireAdmin);
    $router->get('/admin/forms/{id:\d+}/export', [MessagesController::class, 'export'], $requireAdmin);

    // Site settings (D-028): the one screen that edits what the installer wrote, plus the
    // maintenance message and its switch, which moved off the dashboard.
    // Search across the admin (D-052): the screen, and the ⌘K palette's fragment of it.
    $router->get('/admin/search', [SearchController::class, 'index'], $requireAdmin);
    // The activity log in full (D-052); the Overview shows its start.
    $router->get('/admin/activity', [ActivityController::class, 'index'], $requireAdmin);
    // Light, dark or the machine's own (D-054): the switch in the strip posts here.
    $router->post('/admin/appearance', [AppearanceController::class, 'save'], $requireAdmin);
    $router->get('/admin/settings', [SettingsController::class, 'show'], $requireAdmin);
    $router->post('/admin/settings', [SettingsController::class, 'save'], $requireAdmin);
    $router->post('/admin/settings/maintenance-message', [SettingsController::class, 'saveMessage'], $requireAdmin);
    // Mail (D-045): how the site sends, and a test message to prove it does.
    $router->post('/admin/settings/mail', [MailController::class, 'save'], $requireAdmin);
    $router->post('/admin/settings/mail/test', [MailController::class, 'test'], $requireAdmin);
    // Two-step login (D-050): set up, confirm, new recovery codes, off.
    $router->get('/admin/two-step', [TwoFactorController::class, 'setup'], $requireAdmin);
    $router->post('/admin/two-step', [TwoFactorController::class, 'confirm'], $requireAdmin);
    $router->post('/admin/two-step/codes', [TwoFactorController::class, 'renew'], $requireAdmin);
    $router->post('/admin/two-step/off', [TwoFactorController::class, 'off'], $requireAdmin);
    // Visit statistics (D-051): the screen, the Settings panel's settings, and deleting
    // every count.
    $router->get('/admin/statistics', [StatsController::class, 'index'], $requireAdmin);
    // Taking the counts out and putting them back (O-20).
    $router->get('/admin/statistics/export', [StatsDataController::class, 'export'], $requireAdmin);
    $router->post('/admin/statistics/import', [StatsDataController::class, 'import'], $requireAdmin);
    $router->post('/admin/settings/statistics', [StatsSettingsController::class, 'save'], $requireAdmin);
    $router->post('/admin/settings/statistics/erase', [StatsSettingsController::class, 'erase'], $requireAdmin);
    $router->post('/admin/settings/statistics/countries', [StatsSettingsController::class, 'geoDownload'], $requireAdmin);
    $router->post('/admin/settings/statistics/countries/upload', [StatsSettingsController::class, 'geoUpload'], $requireAdmin);
    // The city database, which comes down in pieces (D-055): begin, carry on, give up.
    $router->post('/admin/settings/statistics/cities', [StatsSettingsController::class, 'cityStart'], $requireAdmin);
    $router->post('/admin/settings/statistics/cities/step', [StatsSettingsController::class, 'cityStep'], $requireAdmin);
    $router->post('/admin/settings/statistics/cities/cancel', [StatsSettingsController::class, 'cityCancel'], $requireAdmin);
    // The site's languages (D-043), a panel on the Settings screen with its own forms. A
    // code is two letters, the ISO 639-1 list the installer offers.
    $router->post('/admin/languages', [LanguagesController::class, 'add'], $requireAdmin);
    $router->post('/admin/languages/{code:[a-z]{2}}/enabled', [LanguagesController::class, 'enabled'], $requireAdmin);
    $router->post('/admin/languages/{code:[a-z]{2}}/move', [LanguagesController::class, 'move'], $requireAdmin);
    $router->post('/admin/languages/{code:[a-z]{2}}/delete', [LanguagesController::class, 'remove'], $requireAdmin);

    // The site's header and footer (D-028, D-030). Its own screen rather than another
    // section of Settings: settings are what the installer wrote and the fallback
    // pictures, while this is what a visitor sees at the top and bottom of every page.
    $router->get('/admin/chrome', [ChromeController::class, 'show'], $requireAdmin);
    $router->post('/admin/chrome', [ChromeController::class, 'save'], $requireAdmin);

    $router->get('/admin/design', [DesignController::class, 'show'], $requireAdmin);
    $router->post('/admin/design', [DesignController::class, 'save'], $requireAdmin);
    $router->get('/admin/design/preview', [DesignController::class, 'preview'], $requireAdmin);
    $router->get('/admin/design/stylesheet', [DesignController::class, 'previewCss'], $requireAdmin);
    $router->get('/admin/design/check', [DesignController::class, 'check'], $requireAdmin);

    // Pages: the home page of a locale has the empty slug. Slugs are one path segment.
    // A visitor sending a form (D-046). Unprefixed: the form knows its own language.
    $router->visitorPost('/form/{id:\d+}', [FormSubmitController::class, 'submit']);
    // The sitemap for a host where public/sitemap.xml cannot be written (D-049).
    $router->get('/sitemap', [PageController::class, 'sitemap']);
    $router->get('/', [PageController::class, 'show']);
    $router->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PageController::class, 'show']);
    $router->setNotFound([PageController::class, 'notFound']);

    return $router;
});

return $container;
