<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;

function attemptAvatarUpload(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('avatar.store'));
}

function attemptPasswordChange(User $user): TestResponse
{
    return test()->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);
}

it('bounds avatar uploads without spending the password budget', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 20) as $ignored) {
        expect(attemptAvatarUpload($user)->status())->not->toBe(429);
    }

    attemptAvatarUpload($user)->assertTooManyRequests();

    attemptPasswordChange($user)->assertSessionHasErrors('current_password');
});

it('bounds password changes without spending the avatar budget', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        attemptPasswordChange($user)->assertSessionHasErrors('current_password');
    }

    attemptPasswordChange($user)->assertTooManyRequests();

    expect(attemptAvatarUpload($user)->status())->not->toBe(429);
});

it('counts each person separately', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        attemptPasswordChange($user);
    }

    attemptPasswordChange(User::factory()->create())->assertSessionHasErrors('current_password');
});
