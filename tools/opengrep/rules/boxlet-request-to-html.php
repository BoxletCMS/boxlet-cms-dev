<?php

final class SearchController
{
    public function bad(Request $request): Response
    {
        $q = $request->query['q'] ?? '';
        // ruleid: boxlet-request-to-html
        return Response::html('<p>Results for ' . $q . '</p>');
    }

    public function view(Request $request): void
    {
        // ruleid: boxlet-request-to-html
        echo $request->input('name');
        // ok: boxlet-request-to-html
        echo e($request->input('name'));
    }

    public function good(Request $request): Response
    {
        // ok: boxlet-request-to-html
        return Response::html('<p>Results for ' . e((string) ($request->query['q'] ?? '')) . '</p>');
    }
}
