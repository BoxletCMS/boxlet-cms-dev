<?php

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Support\Url;
use Dotenv\Dotenv;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Dependencies are missing. Run composer install, or upload the release ZIP which includes vendor/.';
    exit;
}

require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();
ErrorHandler::register((bool) env('APP_DEBUG', false));

$container = require $root . '/app/bootstrap.php';
$request = Request::fromGlobals();

Url::configure($request->basePath, (bool) $container->get('config')->get('app.pretty_urls', true));

$container->get('router')->dispatch($request)->send();
