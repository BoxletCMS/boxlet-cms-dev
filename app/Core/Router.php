<?php

namespace App\Core;

use App\Support\Url;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Locale-aware front-end router over FastRoute. Routes are registered without the
 * locale; every request is resolved to a locale before a route is matched.
 *
 *   /                 302 to /{default}/
 *   /hello            302 to /{default}/hello   (no locale segment)
 *   /de/hello         404 when de is not enabled (never redirected: soft 404)
 *   /en               302 to /en/
 *   /en/hello         dispatch "/hello" with locale "en"
 *
 * A first segment counts as a locale segment when it is shaped like a locale code.
 */
final class Router
{
    private const LOCALE_SHAPE = '~^[a-z]{2}(-[a-z]{2,4})?$~i';

    /** @var list<array{string, string, array{class-string, string}}> */
    private array $routes = [];

    /** @var array{class-string, string}|null */
    private ?array $notFound = null;

    /**
     * @param list<string> $locales enabled locale codes
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $locales,
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * @param array{class-string, string} $handler controller class and method
     */
    public function get(string $path, array $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    /**
     * @param array{class-string, string} $handler
     */
    public function setNotFound(array $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path;
        $first = explode('/', ltrim($path, '/'), 2)[0];

        if ($first === '') {
            return Response::redirect(Url::page($this->defaultLocale));
        }
        if (!preg_match(self::LOCALE_SHAPE, $first)) {
            return Response::redirect(Url::page($this->defaultLocale, $path));
        }
        if (!in_array($first, $this->locales, true)) {
            return $this->notFound($request, $this->defaultLocale);
        }

        $routePath = substr($path, strlen($first) + 1);
        if ($routePath === '') {
            return Response::redirect(Url::page($first));
        }

        $result = $this->dispatcher()->dispatch($request->method, $routePath);

        return match ($result[0]) {
            Dispatcher::FOUND => $this->call($result[1], $request, $first, $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($request, $first, $result[1]),
            default => $this->notFound($request, $first),
        };
    }

    private function dispatcher(): Dispatcher
    {
        return simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$method, $path, $handler]) {
                $collector->addRoute($method, $path, $handler);
            }
        });
    }

    /**
     * @param array{class-string, string} $handler
     * @param array<string, string>       $params
     */
    private function call(array $handler, Request $request, string $locale, array $params): Response
    {
        [$class, $method] = $handler;

        return (new $class($this->container))->$method($request, $locale, $params);
    }

    private function notFound(Request $request, string $locale): Response
    {
        if ($this->notFound === null) {
            return Response::html('Not Found', 404);
        }
        $response = $this->call($this->notFound, $request, $locale, []);
        $response->status = 404;

        return $response;
    }

    /**
     * @param list<string> $allowed
     */
    private function methodNotAllowed(Request $request, string $locale, array $allowed): Response
    {
        $response = $this->notFound($request, $locale);
        $response->status = 405;
        $response->headers['Allow'] = implode(', ', $allowed);

        return $response;
    }
}
