<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in account, as a `User` rather than a `User|null`.
     *
     * Every route that reaches a controller here is behind `auth`, so the null is a shape the
     * type system knows about and the application does not — and fourteen controllers had each
     * written this method out to say so. A 403 rather than a 404: the framework has already
     * decided somebody is signed in, and if that stops being true it is a broken assumption
     * rather than a missing page.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }

    /**
     * One read behind several lazy props: done the first time any of them is resolved, and not at
     * all for a partial reload that asks for none of them.
     *
     * Held by the closure rather than by Laravel's `once()`, which keys its memo on the calling
     * object — and the route keeps the controller between requests.
     *
     * @template TRead of array
     *
     * @param  Closure(): TRead  $read
     * @return Closure(): TRead
     */
    protected function memoized(Closure $read): Closure
    {
        $value = null;

        // No native return type: `array` would be wider than `TRead`, and the shape of what was
        // read is what the props built on it are typed from.
        return function () use ($read, &$value) {
            return $value ??= $read();
        };
    }
}
