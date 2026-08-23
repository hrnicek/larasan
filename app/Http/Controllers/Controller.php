<?php

namespace App\Http\Controllers;

use App\Models\User;
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
}
