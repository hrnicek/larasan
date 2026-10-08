<?php

declare(strict_types=1);

use App\Domain\Account\Actions\UploadAvatar;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('refuses to be framed by another site and to have its content types guessed', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sends the same headers on a signed-in page, an Inertia visit and a stale-asset conflict', function (): void {
    $actor = memberOf(Workspace::factory()->create());
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($actor)
        ->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($actor)
        ->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale'])
        ->assertStatus(409)
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it("keeps the manifest's content type", function (): void {
    $this->get(route('manifest'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sends each header once on a file the controller already marks', function (): void {
    Storage::fake('avatars');
    $person = memberOf(Workspace::factory()->create());
    app(UploadAvatar::class)->handle($person, UploadedFile::fake()->image('me.png', 64, 64));

    $response = $this->actingAs($person)
        ->get(route('users.avatar', ['user' => $person->id, 'v' => pathinfo((string) $person->avatar_path, PATHINFO_FILENAME)]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->headers->all('X-Content-Type-Options'))->toBe(['nosniff'])
        ->and($response->headers->all('X-Frame-Options'))->toBe(['SAMEORIGIN']);
});

it('marks a response the router answers without a route', function (): void {
    $this->get('/a-path-that-does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
