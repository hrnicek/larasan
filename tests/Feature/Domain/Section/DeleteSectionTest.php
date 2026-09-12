<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('deletes the section outright', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    app(DeleteSection::class)->handle($section, $actor);

    expect(Section::query()->whereKey($section->id)->exists())->toBeFalse()
        ->and($project->sections()->count())->toBe(0);
});

it('leaves the other columns where they were', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');
    $positions = [$a->position, $c->position];

    app(DeleteSection::class)->handle($b, $actor);

    expect($a->fresh()?->position)->toBe($positions[0])
        ->and($c->fresh()?->position)->toBe($positions[1])
        ->and($project->sections()->count())->toBe(2);
});

it('allows deleting the last remaining column', function (): void {
    [$project, $actor] = projectEditableBy();
    $only = addSection($project, $actor, 'Everything');

    app(DeleteSection::class)->handle($only, $actor);

    expect($project->sections()->count())->toBe(0);
});

it('announces the deletion', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor);
    Event::fake();

    app(DeleteSection::class)->handle($section, $actor);

    Event::assertDispatched(SectionDeleted::class, fn (SectionDeleted $event): bool => $event->sectionId === $section->id
        && $event->projectId === $project->id
        && $event->deletedById === $actor->id);
});

it('refuses an actor who may not shape the project', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $viewer = memberOf($project->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()->in($project)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(fn () => app(DeleteSection::class)->handle($section, $viewer))
        ->toThrow(SectionException::class, 'permission to change the sections');

    expect(Section::query()->whereKey($section->id)->exists())->toBeTrue();
});

it('refuses someone outside the workspace entirely', function (): void {
    [$project, $editor] = projectEditableBy();
    $section = addSection($project, $editor, 'Backlog');
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn () => app(DeleteSection::class)->handle($section, $outsider))
        ->toThrow(SectionException::class);

    expect(Section::query()->whereKey($section->id)->exists())->toBeTrue();
});

it('moves the cards in the column to the ungrouped bucket, in the order they were in', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    $cards = collect(['A', 'B', 'C'])->map(fn (string $title, int $index): TaskProjectMembership => TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => $title]), $project)
        ->inSection($section)
        ->at(($index + 1) * SparsePosition::GAP)
        ->create());

    app(DeleteSection::class)->handle($section, $actor);

    $ungrouped = $project->placements()->whereNull('section_id')->orderBy('position')->with('task')->get();

    expect($ungrouped->pluck('task.title')->all())->toBe(['A', 'B', 'C'])
        ->and($ungrouped->pluck('id')->all())->toBe($cards->pluck('id')->all())
        ->and(Task::query()->count())->toBe(3);
});

it('does not collide with cards already in the ungrouped bucket', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    // Equal positions in two buckets are legal; relying on nullOnDelete would put both cards in one slot.
    $ungrouped = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => 'Loose']), $project)
        ->at(SparsePosition::GAP)
        ->create();

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => 'Boxed']), $project)
        ->inSection($section)
        ->at(SparsePosition::GAP)
        ->create();

    app(DeleteSection::class)->handle($section, $actor);

    $order = $project->placements()->whereNull('section_id')->orderBy('position')->with('task')->get();

    expect($order->pluck('task.title')->all())->toBe(['Loose', 'Boxed'])
        ->and($order->pluck('position')->unique())->toHaveCount(2)
        ->and($ungrouped->refresh()->position)->toBe(SparsePosition::GAP);
});

it('leaves the cards of another column alone', function (): void {
    [$project, $actor] = projectEditableBy();
    $going = addSection($project, $actor, 'Going');
    $staying = addSection($project, $actor, 'Staying');

    $kept = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->inSection($staying)
        ->create();

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->inSection($going)
        ->create();

    app(DeleteSection::class)->handle($going, $actor);

    expect($staying->placements()->pluck('id')->all())->toBe([$kept->id])
        ->and($kept->refresh()->position)->toBe($kept->position);
});

it('locks the project row before it touches a card, and scopes the rewrite to the project', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->inSection($section)
        ->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DeleteSection::class)->handle($section, $actor);

    DB::disableQueryLog();

    $log = collect(DB::getQueryLog());

    $projectLock = $log->search(fn (array $entry): bool => str_contains($entry['query'], 'from "projects"')
        && str_ends_with($entry['query'], 'for no key update'));

    $firstCardStatement = $log->search(fn (array $entry): bool => str_contains($entry['query'], 'task_project_memberships'));

    $rewrite = $log->first(fn (array $entry): bool => str_starts_with(trim($entry['query']), 'update task_project_memberships'));

    expect($projectLock)->toBeInt()
        ->and($firstCardStatement)->toBeInt()
        ->and($projectLock)->toBeLessThan($firstCardStatement)
        ->and($rewrite)->not->toBeNull()
        ->and($rewrite['bindings'])->toContain($project->id);
});

it('recovers when an append took the end of the ungrouped bucket between the read and the write', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Doing');

    $boxed = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(['title' => 'Boxed']), $project)
        ->inSection($section)
        ->create();

    $squatter = Task::factory()->in($project->workspace)->create(['title' => 'Squatter']);
    $injected = false;

    DB::listen(function (QueryExecuted $query) use (&$injected, $project, $squatter): void {
        if ($injected
            || ! str_contains($query->sql, 'task_project_memberships')
            || ! str_contains($query->sql, '"section_id" is null')) {
            return;
        }

        $injected = true;

        DB::table('task_project_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => $squatter->id,
            'project_id' => $project->id,
            'section_id' => null,
            'position' => SparsePosition::GAP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    app(DeleteSection::class)->handle($section, $actor);

    // The squatter is rolled back with the failed attempt, so only the retry's writes survive.
    expect($injected)->toBeTrue()
        ->and(Section::query()->whereKey($section->id)->exists())->toBeFalse()
        ->and($boxed->refresh()->section_id)->toBeNull()
        ->and($project->placements()->whereNull('section_id')->pluck('position')->all())->toBe([SparsePosition::GAP]);
});
