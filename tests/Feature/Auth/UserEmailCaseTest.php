<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

it('stores a registered address in lower case', function (): void {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Jana',
        'email' => 'Jana@Example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->email)->toBe('jana@example.com');
});

it('refuses to register an address an account already holds in another case', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    User::factory()->create()->forceFill(['email' => 'Jana@Example.com'])->save();

    $this->post(route('register.store'), [
        'name' => 'Jana',
        'email' => 'jana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->whereRaw('lower(email) = ?', ['jana@example.com'])->count())->toBe(1);
});

it('rejects two accounts whose addresses differ only in case at the database', function (): void {
    User::factory()->create(['email' => 'jana@example.com']);

    expect(fn () => DB::transaction(fn (): User => User::factory()->create(['email' => 'JANA@example.com'])))
        ->toThrow(QueryException::class);

    expect(User::query()->count())->toBe(1);
});
