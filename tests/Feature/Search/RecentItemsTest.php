<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Search\Actions\RecordRecentlyOpened;
use App\Domain\Search\Models\RecentItem;
use App\Domain\Search\Queries\RecentItemsForUser;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return list<array<string, mixed>>
 */
function recentsFor(Workspace $workspace, User $actor): array
{
    return app(RecentItemsForUser::class)($workspace, $actor);
}

it('remembers a task somebody opened at its own address', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    $this->actingAs($actor)->get(route('tasks.show', $task))->assertOk();

    expect(recentsFor($workspace, $actor))->toHaveCount(1)
        ->and(recentsFor($workspace, $actor)[0]['title'])->toBe('Fix the login screen');
});

it('remembers a task opened as a panel over another screen', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    // The panel is an address on whichever screen it was opened from (TASK-100-003).
    $this->actingAs($actor)->get(route('search.index', ['task' => $task->id]))->assertOk();

    expect(recentsFor($workspace, $actor))->toHaveCount(1);
});

it('remembers a project somebody opened', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);

    $this->actingAs($actor)->get(route('projects.show', $project))->assertOk();

    $recents = recentsFor($workspace, $actor);

    expect($recents)->toHaveCount(1)
        ->and($recents[0]['kind'])->toBe('projects')
        ->and($recents[0]['title'])->toBe('Website relaunch');
});

it('moves a row rather than keeping two when the same thing is opened twice', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)->get(route('tasks.show', $task))->assertOk();
    $this->actingAs($actor)->get(route('tasks.show', $task))->assertOk();

    expect(RecentItem::query()->count())->toBe(1);
})->with([
    'the palette asks what somebody was in, not how often they were in it',
]);

it('keeps the most recent first', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $first = Task::factory()->in($workspace)->create(['title' => 'Opened first']);
    $second = Task::factory()->in($workspace)->create(['title' => 'Opened second']);

    app(RecordRecentlyOpened::class)->handle($workspace, $actor, $first);
    $this->travel(1)->minutes();
    app(RecordRecentlyOpened::class)->handle($workspace, $actor, $second);

    expect(recentsFor($workspace, $actor)[0]['title'])->toBe('Opened second');
});

it('never offers something the actor may no longer open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)->get(route('tasks.show', $task))->assertOk();
    expect(recentsFor($workspace, $actor))->toHaveCount(1);

    $project->update(['visibility' => 'private']);

    expect(recentsFor($workspace, $actor))->toBe([]);
})->with([
    'a list of things somebody used to be allowed to see is the same leak a stale index would be',
]);

it('never offers a private project to somebody who lost their membership', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->private()->create();
    $membership = ProjectMembership::factory()
        ->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();

    app(RecordRecentlyOpened::class)->handle($workspace, $actor, $project);
    expect(recentsFor($workspace, $actor))->toHaveCount(1);

    $membership->delete();

    expect(recentsFor($workspace, $actor))->toBe([]);
});

it('keeps one workspace out of another', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    memberOf($elsewhere, user: $actor);
    $task = Task::factory()->in($elsewhere)->create();

    app(RecordRecentlyOpened::class)->handle($elsewhere, $actor, $task);

    expect(recentsFor($workspace, $actor))->toBe([])
        ->and(recentsFor($elsewhere, $actor))->toHaveCount(1);
});

it('keeps one person out of another', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $colleague = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(RecordRecentlyOpened::class)->handle($workspace, $colleague, $task);

    expect(recentsFor($workspace, $actor))->toBe([]);
});

it('stops the table growing without a reader', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (Task::factory()->count(RecordRecentlyOpened::KEPT + 5)->in($workspace)->create() as $task) {
        app(RecordRecentlyOpened::class)->handle($workspace, $actor, $task);
        $this->travel(1)->seconds();
    }

    expect(RecentItem::query()->count())->toBe(RecordRecentlyOpened::KEPT);
})->with([
    'the list has an owner and a size a person can hold in their head, so it trims itself rather
    than needing a scheduled sweep',
]);

it('offers them on the empty field and not beside every keystroke', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    app(RecordRecentlyOpened::class)->handle($workspace, $actor, $task);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions'))
        ->assertOk()
        ->assertJsonCount(1, 'recents')
        ->assertJsonPath('recents.0.title', 'Fix the login screen');

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'login']))
        ->assertOk()
        ->assertJsonCount(0, 'recents');
});
