<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('unauthenticated user sees login page', function (): void {
    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('auth/Login')
        );
});

test('authenticated user sees dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertStatus(200)
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Dashboard')
        );
});
