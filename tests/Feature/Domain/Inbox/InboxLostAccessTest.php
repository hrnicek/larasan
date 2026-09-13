<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Models\Task;
use Inertia\Testing\AssertableInertia;

it('names neither the task nor its id once the reader has lost access to it', function (): void {
    [$private, $owner] = projectFor(WorkspaceRole::Owner, ProjectAccessLevel::Owner, visibility: ProjectVisibility::Private);
    $workspace = $private->workspace;
    $reader = memberOf($workspace);
    $membership = ProjectMembership::factory()->in($private)->forUser($reader)->withAccess(ProjectAccessLevel::Editor)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Negotiate severance']);
    TaskProjectMembership::factory()->placing($task, $private)->create();
    app(AssignTask::class)->handle($task, $owner, $reader);

    $membership->delete();

    expect(inbox($workspace, $reader)['notifications'][0]['subject'])->toBe([
        'type' => 'task',
        'id' => null,
        'title' => null,
        'url' => null,
        'projects' => [],
    ]);

    $this->actingAs($reader)
        ->get(route('inbox.index'))
        ->assertOk()
        ->assertDontSee('Negotiate severance')
        ->assertDontSee($task->id)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('notifications.0.subject.id', null)
            ->where('notifications.0.subject.title', null)
            ->where('notifications.0.subject.url', null));
});

it('names neither a subtask nor its id when it inherits a private project the reader is not in', function (): void {
    [$private, $owner] = projectFor(WorkspaceRole::Owner, ProjectAccessLevel::Owner, visibility: ProjectVisibility::Private);
    $workspace = $private->workspace;
    $reader = memberOf($workspace);
    $membership = ProjectMembership::factory()->in($private)->forUser($reader)->withAccess(ProjectAccessLevel::Editor)->create();

    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $private)->create();
    $subtask = Task::factory()->in($workspace)->create(['title' => 'Draft the letter', 'parent_id' => $parent->id]);

    app(AssignTask::class)->handle($subtask, $owner, $reader);

    $membership->delete();

    $subject = inbox($workspace, $reader)['notifications'][0]['subject'];

    expect($subject['id'])->toBeNull()
        ->and($subject['title'])->toBeNull()
        ->and($subject['url'])->toBeNull();
});

it('still names a subtask the reader reaches through its parent s project', function (): void {
    [$private, $owner] = projectFor(WorkspaceRole::Owner, ProjectAccessLevel::Owner, visibility: ProjectVisibility::Private);
    $workspace = $private->workspace;
    $reader = memberOf($workspace);
    ProjectMembership::factory()->in($private)->forUser($reader)->withAccess(ProjectAccessLevel::Viewer)->create();

    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $private)->create();
    $subtask = Task::factory()->in($workspace)->create(['title' => 'Draft the letter', 'parent_id' => $parent->id]);

    app(AssignTask::class)->handle($subtask, $owner, $reader);

    $subject = inbox($workspace, $reader)['notifications'][0]['subject'];

    expect($subject['id'])->toBe($subtask->id)
        ->and($subject['title'])->toBe('Draft the letter')
        ->and($subject['url'])->toBe(route('tasks.show', $subtask->id));
});

it('shows a guest who told them without that person s address', function (): void {
    [$private, $owner] = projectFor(WorkspaceRole::Owner, ProjectAccessLevel::Owner, visibility: ProjectVisibility::Private);
    $workspace = $private->workspace;
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $member = memberOf($workspace);
    ProjectMembership::factory()->in($private)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    ProjectMembership::factory()->in($private)->forUser($member)->withAccess(ProjectAccessLevel::Viewer)->create();

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    app(AssignTask::class)->handle($task, $owner, $guest);
    app(AssignTask::class)->handle($task, $owner, $member);

    expect(inbox($workspace, $guest)['notifications'][0]['actor'])->toBe([
        'id' => $owner->id,
        'name' => $owner->name,
        'avatar' => null,
    ])
        ->and(inbox($workspace, $member)['notifications'][0]['actor'])->toHaveKey('email', $owner->email);
});
