<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    [$workspace] = placeableProject();

    $this->get(route('tasks.show', Task::factory()->in($workspace)->create()))
        ->assertRedirect(route('login'));
});

it('renders a task at its own url', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Write it down']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('tasks/Show')
            ->where('task.title', 'Write it down')
            ->where('can.update', true)
            ->has('placements', 1));
});

it('renders a task that is in no project at all', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    // A task with no placements is still a task (ADR-0003), and its page says so rather than
    // refusing to render.
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('placements', 0));
});

it('hides a task in another workspace behind a 404', function (): void {
    [, , $actor] = placeableProject();
    [$otherWorkspace] = placeableProject();

    $this->actingAs($actor)
        ->get(route('tasks.show', Task::factory()->in($otherWorkspace)->create()))
        ->assertNotFound();
});

it('refuses a task that lives only in a project the actor was not given', function (): void {
    [$workspace, , $actor] = placeableProject();
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // They can see the workspace, so the task is not a secret's existence — the access is
    // what is refused (TASK-070-017).
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertForbidden();
});

it('lets a guest read a task in a project they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($guest)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', false)
            // Viewer is the level below Commenter: they were given the project to read
            // (ADR-0006), and the panel offers them neither control.
            ->where('can.comment', false));
});

it('sends the lists the detail s own controls need', function (): void {
    [$workspace, , $actor] = placeableProject();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($member): void {
            $props = $page->toArray()['props'];

            // The same two lists the project screen sends, because the panel's controls are
            // the same components the list row uses.
            expect(array_column($props['members'], 'id'))->toContain($member->id)
                ->and($props['priorities'])->toContain('urgent');
        });
});

it('renames a task from its own page', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Old name', 'description' => 'Kept']);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['title' => 'New name'])
        ->assertRedirect(route('tasks.show', $task));

    // Only the title was sent, so the description is untouched (TASK-080-008).
    expect($task->fresh()?->title)->toBe('New name')
        ->and($task->fresh()?->description)->toBe('Kept');
});

it('clears a description with an explicit null and refuses a blank title', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['description' => 'Going']);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['description' => null])
        ->assertRedirect();

    expect($task->fresh()?->description)->toBeNull();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['title' => '   '])
        ->assertSessionHasErrors('title');
});

it('creates a subtask from the detail', function (): void {
    [$workspace, , $actor] = placeableProject();
    $parent = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $parent))
        ->post(route('tasks.store'), ['title' => 'A smaller piece', 'parent_id' => $parent->id])
        ->assertRedirect(route('tasks.show', $parent));

    expect($parent->children()->pluck('title')->all())->toBe(['A smaller piece']);
});

it('surfaces the depth limit rather than pre-empting it', function (): void {
    [$workspace, , $actor] = placeableProject();

    $deepest = Task::factory()->in($workspace)->create();

    foreach (range(1, ParentChain::MAX_DEPTH - 1) as $ignored) {
        $deepest = Task::factory()->in($workspace)->create(['parent_id' => $deepest->id]);
    }

    /*
     * The limit belongs to the Action (`ParentChain::MAX_DEPTH`); the client surfaces its
     * refusal rather than counting depth itself, because a second copy of the rule is the one
     * that drifts. `TaskController` translates the refusal onto the field it is about, which
     * is a better answer than the generic `refusal` key the renderer would give it.
     */
    $this->actingAs($actor)
        ->from(route('tasks.show', $deepest))
        ->post(route('tasks.store'), ['title' => 'One too deep', 'parent_id' => $deepest->id])
        ->assertSessionHasErrors(['parent_id' => 'Subtasks cannot be nested that deeply.']);

    expect($deepest->children()->count())->toBe(0);
});

it('says who is watching and whether the reader is', function (): void {
    [$workspace, , $actor] = placeableProject();
    $other = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    app(FollowTask::class)->handle($task, $other);

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('followers', 1)
            ->where('followers.0.id', $other->id)
            ->where('following', false));
});

it('starts and stops watching from the detail', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->post(route('tasks.follow', $task))
        ->assertRedirect(route('tasks.show', $task));

    expect($task->followers()->pluck('users.id')->all())->toBe([$actor->id]);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->delete(route('tasks.unfollow', $task))
        ->assertRedirect();

    expect($task->follows()->count())->toBe(0);
});

it('refuses to start watching a task the actor cannot reach', function (): void {
    [$workspace, , $actor] = placeableProject();
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $this->actingAs($actor)
        ->post(route('tasks.follow', $task))
        ->assertForbidden();

    expect($task->follows()->count())->toBe(0);
});

it('defers the activity region rather than holding the page for it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    // The first response carries everything the reader needs and not the region that can be
    // slow — the case the frontend rule about skeletons was written for.
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('task')
            ->missing('activity'));
});

it('sends the activity when the region asks for it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    /*
     * The follow-up request a `<Deferred>` region makes. The asset-version middleware is
     * skipped because this test is about the deferred prop, not about versioning — with it in
     * place the request would 409 on a version header a test cannot know.
     */
    $this->actingAs($actor)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('tasks.show', $task), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'tasks/Show',
            'X-Inertia-Partial-Data' => 'activity',
        ])
        ->assertOk()
        ->assertJsonPath('component', 'tasks/Show')
        // A partial response is JSON rather than a rendered page, so it is read as JSON: the
        // region asked for `activity` and `activity` is what came back.
        ->assertJsonStructure(['props' => ['activity' => ['entries', 'meta']]]);
});

it('answers the deferred region with the thread itself', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->by($actor)->create(['body' => 'Looks right to me']);

    // The region has been answering with an empty array since TASK-100-011. This is the task
    // that gives it something to say.
    $this->actingAs($actor)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('tasks.show', $task), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'tasks/Show',
            'X-Inertia-Partial-Data' => 'activity',
        ])
        ->assertOk()
        ->assertJsonPath('props.activity.entries.0.body', 'Looks right to me')
        ->assertJsonPath('props.activity.entries.0.kind', 'comment')
        ->assertJsonPath('props.activity.meta.total', 1);
});

it('sends each thread line with the permissions its controls render from', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->by($actor)->create(['body' => 'Mine']);
    Comment::factory()->on($task)->create(['body' => 'Theirs']);

    // The Edit and Delete controls are rendered from these, never from a rule written into the
    // template — a second copy of the policy in a component is the copy that goes stale.
    $this->actingAs($actor)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('tasks.show', $task), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'tasks/Show',
            'X-Inertia-Partial-Data' => 'activity',
        ])
        ->assertOk()
        // Newest first from the server; the component reverses a page to draw it.
        ->assertJsonPath('props.activity.entries.0.canEdit', false)
        ->assertJsonPath('props.activity.entries.0.canDelete', true)
        ->assertJsonPath('props.activity.entries.1.canEdit', true)
        ->assertJsonPath('props.activity.entries.1.canDelete', true);
});

it('tells the panel whether a comment form belongs on the screen', function (
    ProjectAccessLevel $access,
    bool $mayComment,
): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess($access)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    /*
     * A guest holds `comment.create` and nothing else (ADR-0010), and the level they were given
     * decides whether that reaches this project: Commenter is the level that exists for exactly
     * this — somebody outside the team taking part in one piece of work — and Viewer is the one
     * below it. The flag is what the component hides the form by, and hiding is right where
     * disabling would be an affordance leading nowhere.
     */
    $this->actingAs($guest)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('can.comment', $mayComment));
})->with([
    'commenter' => [ProjectAccessLevel::Commenter, true],
    'editor' => [ProjectAccessLevel::Editor, true],
    'viewer' => [ProjectAccessLevel::Viewer, false],
]);

it('sends a task s tags and the workspace vocabulary to pick from', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $applied = Tag::factory()->in($workspace)->named('Bug')->create(['color' => ProjectColor::Rose]);
    Tag::factory()->in($workspace)->named('Docs')->create();
    tagTask($task, $applied, $actor);

    // The picker offers what the workspace already has; making a tag is a different permission
    // and a different control (TASK-140-004).
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'Bug')
            ->where('tags.0.color', 'rose')
            ->has('availableTags', 2));
});

it('offers no tags from another workspace', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    Tag::factory()->create(['name' => 'Somebody else s']);

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('availableTags', 0));
});
