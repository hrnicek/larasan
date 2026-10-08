<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginViewResponse;

class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|LoginViewResponse
    {
        if ($request->user() !== null) {
            return to_route('dashboard');
        }

        return app(LoginViewResponse::class);
    }
}
