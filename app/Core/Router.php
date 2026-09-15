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
 * A first segment is a locale only if it is an enabled locale. With primary "en" and
 * "hr" enabled:
 *
 *   /hello            dispatch "/hello" with locale "en"
 *   /hr/hello         dispatch "/hello" with locale "hr"
 *   /en/hello         301 to /hello        (primary never carries a prefix)
 *   /en/  and  /en    301 to /
 *   /hr               301 to /hr/
 *   /de/hello         dispatch "/de/hello" with locale "en", which 404s
 *
 * /, /hr/ and every other home page 404 until Slice 3 adds a home route. Expected.
 */
final class Router
{
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
        private readonly string $primaryLocale,
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
        $locale = $this->primaryLocale;
        $segments = explode('/', ltrim($path, '/'), 2);

        if (in_array($segments[0], $this->locales, true)) {
            $locale = $segments[0];
            $slug = $segments[1] ?? null;

            // Permanent: the primary locale is immutable, so these forms never change.
            if ($locale === $this->primaryLocale || $slug === null) {
                return Response::redirect(Url::page($locale, $slug ?? ''), 301);
            }
            $path = substr($path, strlen($locale) + 1);
        }

        $result = $this->dispatcher()->dispatch($request->method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => $this->call($result[1], $request, $locale, $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($request, $locale, $result[1]),
            default => $this->notFound($request, $locale),
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
