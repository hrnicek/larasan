<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('creates an account that can log in and see something', function (): void {
    $this->seed(DevelopmentSeeder::class);

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->workspaces()->pluck('name')->all())->toBe(['Acme', 'Side Project'])
        ->and($user->projects()->count())->toBe(3);

    $this->post(route('login'), ['email' => 'owner@example.com', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('produces the shapes the application produces, not shapes only a factory can make', function (): void {
    $this->seed(DevelopmentSeeder::class);

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
    $workspace = $user->workspaces()->where('workspaces.name', 'Acme')->firstOrFail();

    expect($workspace->membershipFor($user)?->role)->toBe(WorkspaceRole::Owner)
        ->and($workspace->owner_id)->toBe($user->id);

    $project = Project::query()->where('name', 'Website')->firstOrFail();

    expect($project->memberFor($user)?->access_level->canManageProject())->toBeTrue()
        ->and($project->isManageableBy($user))->toBeTrue();
});

it('seeds addresses only on a reserved domain, since invitations send mail', function (): void {
    $this->seed(DevelopmentSeeder::class);

    expect(User::query()->pluck('email')->all())->each->toEndWith('@example.com');
});

it('changes nothing when it runs twice', function (): void {
    $this->seed(DevelopmentSeeder::class);

    $counts = [
        'users' => User::query()->count(),
        'projects' => Project::query()->count(),
    ];

    $this->seed(DevelopmentSeeder::class);

    expect(User::query()->count())->toBe($counts['users'])
        ->and(Project::query()->count())->toBe($counts['projects']);
});

it('refuses to run in production, where the password would be a way in', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'refused in production');
})->after(fn () => app()->detectEnvironment(fn (): string => 'testing'));
