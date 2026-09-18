<?php

use App\Core\Blocks;
use App\Core\Config;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Modules\Admin\DashboardController;
use App\Modules\Admin\RequireAdmin;
use App\Modules\Auth\AuthController;
use App\Modules\Design\Design;
use App\Modules\Design\DesignController;
use App\Modules\Pages\PageBlockController;
use App\Modules\Pages\PageBuilderController;
use App\Modules\Pages\PageController;
use App\Modules\Pages\PageEditorController;
use App\Modules\Media\MediaController;
use App\Modules\Media\MediaCropController;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaItemController;
use App\Modules\Media\MediaLibrary;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;
use App\Modules\Pages\PagesController;
use App\Modules\Menus\MenusController;
use App\Modules\Settings\ChromeController;
use App\Modules\Settings\SettingsController;
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
// Media (SPEC §5.5). The encoder probes what this server can actually write, so a host
// without a WebP delegate refuses clearly instead of writing files nobody can open.
$container->set('media_encoder', fn () => new MediaEncoder());
$container->set('media_writer', fn (Container $c) => new MediaWriter($c->get('media_encoder')));
$container->set('media_upload', fn (Container $c) => new MediaUpload($c->get('db'), $storage, $c->get('media_encoder')));
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
    $router->post('/admin/pages/{id:\d+}/delete', [PagesController::class, 'delete'], $requireAdmin);

    // Pictures (SPEC §5.5). The generated variants live under /m/ and are served from
    // disk by the web server; no route here ever answers for one.
    $router->get('/admin/media', [MediaController::class, 'index'], $requireAdmin);
    $router->post('/admin/media', [MediaController::class, 'store'], $requireAdmin);
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

    // Site settings (D-028): the one screen that edits what the installer wrote, plus the
    // maintenance message and its switch, which moved off the dashboard.
    $router->get('/admin/settings', [SettingsController::class, 'show'], $requireAdmin);
    $router->post('/admin/settings', [SettingsController::class, 'save'], $requireAdmin);
    $router->post('/admin/settings/maintenance-message', [SettingsController::class, 'saveMessage'], $requireAdmin);

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
    $router->get('/', [PageController::class, 'show']);
    $router->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PageController::class, 'show']);
    $router->setNotFound([PageController::class, 'notFound']);

    return $router;
});

return $container;
