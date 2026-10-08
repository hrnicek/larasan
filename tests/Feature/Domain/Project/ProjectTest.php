<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;

it('casts its enums and dates', function (): void {
    $project = Project::factory()->create([
        'visibility' => ProjectVisibility::Private,
        'default_view' => ProjectDefaultView::Board,
        'start_date' => '2026-01-01',
        'due_date' => '2026-02-01',
    ]);

    expect($project->visibility)->toBe(ProjectVisibility::Private)
        ->and($project->default_view)->toBe(ProjectDefaultView::Board)
        ->and($project->start_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($project->due_date?->toDateString())->toBe('2026-02-01')
        ->and($project->id)->toBeUuid();
});

it('relates to its workspace, owner and creator', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $project = Project::factory()->in($workspace)->ownedBy($user)->create();

    expect($project->workspace->is($workspace))->toBeTrue()
        ->and($project->owner?->is($user))->toBeTrue()
        ->and($project->creator?->is($user))->toBeTrue();
});

it('counts past a slug already taken in the same workspace', function (): void {
    $workspace = Workspace::factory()->create();
    Project::factory()->in($workspace)->create(['slug' => 'web']);
    Project::factory()->in($workspace)->create(['slug' => 'web-2']);

    expect(Project::slugFor($workspace, 'Web'))->toBe('web-3');
});

it('lets another workspace hold the same slug', function (): void {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    Project::factory()->in($theirs)->create(['slug' => 'web']);

    expect(Project::slugFor($mine, 'Web'))->toBe('web');
});

it('counts past a soft-deleted slug, because the unique index still holds it', function (): void {
    $workspace = Workspace::factory()->create();
    Project::factory()->in($workspace)->create(['slug' => 'web'])->delete();

    expect(Project::slugFor($workspace, 'Web'))->toBe('web-2');
});

it('falls back when a name slugifies to nothing', function (): void {
    expect(Project::slugFor(Workspace::factory()->create(), '日本語'))->toBe('project');
});

it('knows whether it is archived, and stays reachable when it is', function (): void {
    $archived = Project::factory()->archived()->create();
    $active = Project::factory()->create();

    expect($archived->isArchived())->toBeTrue()
        ->and($active->isArchived())->toBeFalse()
        ->and(Project::query()->active()->pluck('id')->all())->toBe([$active->id])
        ->and(Project::query()->whereKey($archived->id)->exists())->toBeTrue();
});

it('survives a soft delete and can be restored', function (): void {
    $project = Project::factory()->create();

    $project->delete();

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::withTrashed()->whereKey($project->id)->exists())->toBeTrue();

    $project->restore();

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});
