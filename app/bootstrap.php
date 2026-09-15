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
use App\Modules\Design\TokenCompiler;
use App\Modules\Pages\PageController;
use App\Modules\Pages\PageEditorController;
use App\Modules\Pages\PagesController;
use App\Support\Url;

/**
 * Builds the container for the current request and registers routes. Expects
 * vendor/autoload.php and .env to be loaded already. Shared by public/index.php and
 * tests/support.php, so tests dispatch exactly as production does.
 */

$root = dirname(__DIR__);
$config = new Config($root . '/config');
$storage = (string) $config->get('app.storage_path');

$tokensFile = $root . '/public/cache/tokens.css';
if (!is_file($tokensFile)) {
    (new TokenCompiler())->compile($config->get('tokens', []), $tokensFile);
}

// Loaded eagerly: a malformed block definition must fail at boot, not at render.
$blocks = Blocks::discover($root . '/app/Blocks');

$request = Request::fromGlobals();
Url::configure($request->basePath, '');

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

$container->set('router', function (Container $c) use ($request): Router {
    $locales = $c->get('locales');
    $primary = '';
    foreach ($locales as $locale) {
        if ((int) $locale['is_primary'] === 1) {
            $primary = (string) $locale['code'];
        }
    }
    $origin = Url::origin($request->https, (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'), (int) ($_SERVER['SERVER_PORT'] ?? 0));
    Url::configure($request->basePath, $primary, $origin);
    $router = new Router($c, array_column($locales, 'code'), $primary);

    // Admin routes never carry a locale prefix (SPEC §5.1). They are registered before
    // the page route, whose variable pattern would otherwise shadow them.
    $requireAdmin = [[RequireAdmin::class, 'handle']];
    $router->get('/admin/login', [AuthController::class, 'showLogin']);
    $router->post('/admin/login', [AuthController::class, 'login']);
    $router->post('/admin/logout', [AuthController::class, 'logout'], $requireAdmin);
    $router->get('/admin', [DashboardController::class, 'index'], $requireAdmin);
    $router->get('/admin/pages', [PagesController::class, 'index'], $requireAdmin);
    $router->get('/admin/pages/new', [PagesController::class, 'create'], $requireAdmin);
    $router->post('/admin/pages', [PagesController::class, 'store'], $requireAdmin);
    $router->get('/admin/pages/{id:\d+}', [PageEditorController::class, 'edit'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}', [PageEditorController::class, 'update'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/status', [PagesController::class, 'status'], $requireAdmin);
    $router->post('/admin/pages/{id:\d+}/delete', [PagesController::class, 'delete'], $requireAdmin);

    // Pages: the home page of a locale has the empty slug. Slugs are one path segment.
    $router->get('/', [PageController::class, 'show']);
    $router->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PageController::class, 'show']);
    $router->setNotFound([PageController::class, 'notFound']);

    return $router;
});

return $container;
