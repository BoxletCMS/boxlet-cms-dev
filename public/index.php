<?php

use App\Core\ErrorHandler;
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
$container->get('router')->dispatch($container->get('request'))->send();
