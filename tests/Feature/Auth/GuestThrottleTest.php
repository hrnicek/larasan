<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

it('throttles registration attempts per client address', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());

    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'))->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'))->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('register.store'))
        ->assertSessionHasErrors('email');
});

it('throttles password reset link requests per client address', function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => "nobody{$attempt}@example.com"])->assertSessionHasErrors('email');
    }

    $this->post(route('password.email'), ['email' => 'nobody6@example.com'])->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('password.email'), ['email' => 'nobody7@example.com'])
        ->assertSessionHasErrors('email');
});
