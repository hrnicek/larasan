<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (auth()->check()) {
            return Inertia::render('Dashboard');
        }

        return Inertia::render('auth/Login', [
            'canResetPassword' => true,
            'status' => session('status'),
        ]);
    }
}
