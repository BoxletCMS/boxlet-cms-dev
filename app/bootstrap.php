<?php

use App\Core\Config;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
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

$tokensFile = $root . '/public/cache/tokens.css';
if (!is_file($tokensFile)) {
    (new TokenCompiler())->compile($config->get('tokens', []), $tokensFile);
}

$request = Request::fromGlobals();
Url::configure($request->basePath, (bool) $config->get('app.pretty_urls', true), $config->get('locales.primary'));

$container = new Container();
$container->set('config', fn () => $config);
$container->set('request', fn () => $request);
$container->set('db', fn (Container $c) => new Db($c->get('config')->get('database.path')));
$container->set('view', fn () => new View($root . '/app/Modules/Pages/views'));
$container->set('router', function (Container $c): Router {
    $router = new Router(
        $c,
        array_keys($c->get('config')->get('locales.enabled', [])),
        $c->get('config')->get('locales.primary'),
    );

    // TEMPORARY (Slice 1): hard-coded page until Slice 3 serves pages from the database.
    // No home route yet, so / and /hr/ land on the 404 page. That is expected.
    $router->get('/hello', [PageController::class, 'hello']);
    $router->setNotFound([PageController::class, 'notFound']);

    return $router;
});

return $container;
