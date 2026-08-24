<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Ssr\SsrState;
use InertiaUI\Modal\Modal;

/**
 * Inertia Modal, with the one thing it gets wrong on Inertia v3 corrected.
 *
 * Entering a modal's address directly renders the base page and hands the modal to the client
 * as a prop, and the response then has to claim the modal's URL — otherwise the client sees a
 * page whose URL is the base page's, decides the modal does not belong to it, and closes it
 * before it is ever shown. The address bar snaps back to the base page and the modal never
 * appears.
 *
 * The package rewrites that URL in the root view's data. Inertia v3 no longer renders the page
 * JSON from view data: `SsrState` holds it, `<x-inertia::app />` reads it from there, and when
 * server-side rendering is on it emits the SSR body instead — which `SsrState` produced **once**,
 * on the base page's own render, and caches for the rest of the request. Both of those happen
 * before the package's rewrite, so the rewrite lands where nothing reads it.
 *
 * Replacing the instance rather than mutating it is what makes the correction effective: a fresh
 * `SsrState` has not dispatched yet, so the page is rendered again — this time as the modal's
 * address.
 *
 * Measured against `inertiaui/modal` 3.1.2 and `inertiajs/inertia-laravel` 3.3.1. The XHR path
 * is unaffected and is left to the package.
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
