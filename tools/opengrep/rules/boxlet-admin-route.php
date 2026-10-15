<?php

function routes($router, $requireAdmin): void
{
    // ok: boxlet-admin-route
    $router->get('/admin/login', [AuthController::class, 'showLogin']);
    // ok: boxlet-admin-route
    $router->post('/admin/reset/{token:[0-9a-f]{64}}', [ForgotController::class, 'reset']);
    // ok: boxlet-admin-route
    $router->post('/admin/pages/publish', [PagesBulkController::class, 'publish'], $requireAdmin);
    // ruleid: boxlet-admin-route
    $router->post('/admin/pages/delete', [PagesBulkController::class, 'delete']);
    // ruleid: boxlet-admin-route
    $router->get('/admin/login/everything', [AuthController::class, 'showLogin']);
    // ok: boxlet-admin-route
    $router->get('/sitemap.xml', [SitemapController::class, 'show']);
}
