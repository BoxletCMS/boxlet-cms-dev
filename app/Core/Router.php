<?php

namespace App\Core;

use App\Modules\Update\UpdateGate;
use App\Support\Url;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Locale-aware router over FastRoute. Routes are registered without the locale; every
 * request is resolved to a locale before a route is matched.
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
 *   /admin            dispatch "/admin" with the primary locale; admin has no prefix
 *
 * Every non-GET request must carry a valid CSRF token; that is checked here so no
 * route can forget it. /, /hr/ and other home pages 404 until Slice 3.
 */
final class Router
{
    /** @var list<array{string, string, array{class-string, string}, list<array{class-string, string}>}> */
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
     * @param array{class-string, string}       $handler    controller class and method
     * @param list<array{class-string, string}> $middleware class and method returning ?Response
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->routes[] = ['GET', $path, $handler, $middleware];
    }

    /**
     * @param array{class-string, string}       $handler
     * @param list<array{class-string, string}> $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->routes[] = ['POST', $path, $handler, $middleware];
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
        // Before anything else, including the locale redirect: while migrations are
        // pending the answer is the update screen or 503, not a 301 to a page that
        // cannot be rendered (PLAN.md D-019). public/index.php checks the same gate
        // earlier, before this router is even built; this is the one the tests reach.
        $gate = UpdateGate::check($this->container, $request);
        if ($gate !== null) {
            return $gate;
        }

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

        $response = match ($result[0]) {
            Dispatcher::FOUND => $this->run($result[1], $request, $locale, $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($request, $locale, $result[1]),
            default => $this->notFound($request, $locale),
        };

        // While maintenance is on, a logged-in admin sees the real site with a bar saying
        // so. Appended to the finished HTML rather than threaded through every template,
        // so the page above it is exactly the page it would otherwise be.
        return UpdateGate::bar($this->container, $response);
    }

    private function dispatcher(): Dispatcher
    {
        return simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$method, $path, $handler, $middleware]) {
                $collector->addRoute($method, $path, [$handler, $middleware]);
            }
        });
    }

    /**
     * @param array{array{class-string, string}, list<array{class-string, string}>} $route
     * @param array<string, string> $params
     */
    private function run(array $route, Request $request, string $locale, array $params): Response
    {
        [$handler, $middleware] = $route;

        if ($request->method !== 'GET' && $request->method !== 'HEAD'
            && !$this->container->get('session')->validCsrf($request->body['_csrf'] ?? null)) {
            return new Response(t('csrf.invalid'), 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        foreach ($middleware as [$class, $method]) {
            $response = (new $class($this->container))->$method($request);
            if ($response instanceof Response) {
                return $response;
            }
        }

        return $this->call($handler, $request, $locale, $params);
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
