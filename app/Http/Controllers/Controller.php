<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }

    /**
     * Not `once()`: it memoizes per object, and the route reuses the controller instance.
     *
     * @template TRead of array
     *
     * @param  Closure(): TRead  $read
     * @return Closure(): TRead
     */
    protected function memoized(Closure $read): Closure
    {
        $value = null;

        // No native return type, which would widen `TRead` to `array`.
        return function () use ($read, &$value) {
            return $value ??= $read();
        };
    }
}
