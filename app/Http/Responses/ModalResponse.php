<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Ssr\SsrState;
use InertiaUI\Modal\Modal;

/**
 * inertiaui/modal rewrites the URL in view data, but Inertia v3 renders the page from a cached
 * `SsrState`, so a fresh instance is bound carrying the modal's URL.
 */
final class ModalResponse extends Modal
{
    protected function toViewResponse(Request $request, Response $response, string $url): Response
    {
        $page = app(SsrState::class)->page;

        app()->instance(SsrState::class, (new SsrState)->setPage([...$page, 'url' => $url]));

        return parent::toViewResponse($request, $response, $url);
    }
}
