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
use App\Modules\Pages\PageBuilderController;
use App\Modules\Pages\PageController;
use App\Modules\Pages\PageEditorController;
use App\Modules\Pages\PagesController;
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

$request = Request::fromGlobals();
Url::configure($request->basePath, '');
Url::usePublicPath($root . '/public');

$container = new Container();
$container->set('config', fn () => $config);
$container->set('request', fn () => $request);
$container->set('blocks', fn () => $blocks);
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
    $router->post('/admin/pages/{id:\d+}/block', [PageBuilderController::class, 'insert'], $requireAdmin);
    $router->get('/admin/pages/{id:\d+}/form', [PageEditorController::class, 'edit'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}', [PageEditorController::class, 'update'], $requireAdmin);
    $router->post('/admin/pages/order', [PagesController::class, 'reorder'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/status', [PagesController::class, 'status'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/delete', [PagesController::class, 'delete'], $requireAdmin);

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
