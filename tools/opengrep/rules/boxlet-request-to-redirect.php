<?php

final class BackController
{
    public function bad(Request $request): Response
    {
        $back = $request->input('back');
        // ruleid: boxlet-request-to-redirect
        return Response::redirect($back);
    }

    public function alsoBad(Request $request): Response
    {
        // ruleid: boxlet-request-to-redirect
        return new Response('', 302, ['Location' => $request->query['to']]);
    }

    public function good(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        // ok: boxlet-request-to-redirect
        return Response::redirect(Url::admin('pages', $id));
    }

    public function alsoGood(Request $request): Response
    {
        $query = array_filter(['lang' => $request->input('lang')]);
        // ok: boxlet-request-to-redirect
        return Response::redirect(Url::withQuery(Url::admin('pages'), $query));
    }
}
