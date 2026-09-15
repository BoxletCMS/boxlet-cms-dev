<?php

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

$request = Request::fromGlobals();
Url::configure($request->basePath, '');

$container = new Container();
$container->set('config', fn () => $config);
$container->set('request', fn () => $request);
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
    Url::configure($request->basePath, $primary);
    $router = new Router($c, array_column($locales, 'code'), $primary);

    // TEMPORARY (Slice 1): hard-coded page until Slice 3 serves pages from the database.
    // No home route yet, so / and /hr/ land on the 404 page. That is expected.
    $router->get('/hello', [PageController::class, 'hello']);
    $router->setNotFound([PageController::class, 'notFound']);

    // Admin routes never carry a locale prefix (SPEC §5.1).
    $requireAdmin = [[RequireAdmin::class, 'handle']];
    $router->get('/admin/login', [AuthController::class, 'showLogin']);
    $router->post('/admin/login', [AuthController::class, 'login']);
    $router->post('/admin/logout', [AuthController::class, 'logout'], $requireAdmin);
    $router->get('/admin', [DashboardController::class, 'index'], $requireAdmin);

    return $router;
});

return $container;
