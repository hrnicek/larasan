<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\SetTaskCustomFieldValue;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\CreateSection;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Actions\AttachTagToTask;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\MyTasksQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Shared helpers. Pest loads every test file into one process, so a helper declared at
| file scope in two files is a fatal redeclaration, and one declared in a single file is
| missing when another file runs on its own or in a parallel worker.
*/

function memberOf(
    Workspace $workspace,
    WorkspaceRole $role = WorkspaceRole::Member,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
    ?User $user = null,
): User {
    $user = $user ?? User::factory()->create();

    WorkspaceMembership::factory()->withStatus($status)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * @return array{Workspace, User}
 */
function workspaceWith(
    WorkspaceRole $role,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
): array {
    $workspace = Workspace::factory()->create();

    return [$workspace, memberOf($workspace, $role, $status)];
}

/**
 * @return array{Project, User}
 */
function projectFor(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access = null,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
    WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    // Applied last: a project membership can only be created for an active workspace member.
    if ($status !== WorkspaceMembershipStatus::Active) {
        $workspace->membershipFor($actor)?->forceFill(['status' => $status])->save();
    }

    return [$project, $actor];
}

/**
 * A member without a membership row inherits the project's `default_access_level`, so a
 * restriction needs an explicit row.
 */
function viewerOf(Project $project, ProjectAccessLevel $access = ProjectAccessLevel::Viewer): User
{
    $user = memberOf($project->workspace, WorkspaceRole::Member);

    ProjectMembership::factory()->in($project)->forUser($user)->withAccess($access)->create();

    return $user;
}

/**
 * @return array{Workspace, Project, User}
 */
function placeableProject(
    ProjectAccessLevel $access = ProjectAccessLevel::Editor,
    WorkspaceRole $role = WorkspaceRole::Member,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create();

    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    return [$workspace, $project, $actor];
}

/**
 * @return array{Project, User}
 */
function projectEditableBy(
    ProjectAccessLevel $access = ProjectAccessLevel::Editor,
    WorkspaceRole $role = WorkspaceRole::Member,
): array {
    [, $project, $actor] = placeableProject($access, $role);

    return [$project, $actor];
}

/**
 * @return array{Task, User}
 */
function taskEditableBy(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    return [Task::factory()->in($workspace)->create(['title' => 'Untouched']), $actor];
}

function attach(Task $task, Project $project, User $actor): TaskProjectMembership
{
    return app(AttachTaskToProject::class)->handle($task, $project, $actor);
}

function detach(Task $task, Project $project, User $actor): void
{
    app(DetachTaskFromProject::class)->handle($task, $project, $actor);
}

function moveInto(TaskProjectMembership $placement, User $actor, ?Section $section): TaskProjectMembership
{
    return app(MoveTaskInProject::class)->handle($placement, $actor, $section);
}

function moveTo(
    TaskProjectMembership $placement,
    User $actor,
    ?Section $section,
    PlacementTarget $target,
): TaskProjectMembership {
    return app(MoveTaskInProject::class)->handle($placement, $actor, $section, $target);
}

/**
 * @return array<int, string>
 */
function orderIn(Section $section): array
{
    return $section->placements()->with('task')->get()
        ->map(fn (TaskProjectMembership $card): string => (string) $card->task->title)
        ->all();
}

function addSection(Project $project, User $actor, string $name = 'Backlog'): Section
{
    return app(CreateSection::class)->handle($project, $actor, new CreateSectionData(name: $name));
}

function assignTo(Workspace $workspace, User $actor, User $assignee, string $title = 'Fix login'): Task
{
    $task = Task::factory()->in($workspace)->create(['title' => $title]);

    app(AssignTask::class)->handle($task, $actor, $assignee);

    return $task;
}

function tagTask(Task $task, Tag $tag, User $actor): void
{
    app(AttachTagToTask::class)->handle($task, $tag, $actor);
}

/**
 * @param  list<string>  $options
 * @return array{Task, CustomField, User, Project}
 */
function fieldOnATask(CustomFieldType $type = CustomFieldType::Text, array $options = []): array
{
    // Defining a field needs custom_field.manage, which only owners and admins hold. See ADR-0010.
    [$workspace, $project, $actor] = placeableProject(role: WorkspaceRole::Owner);

    $field = app(DefineCustomField::class)->handle($workspace, $actor, 'Estimate', $type, $options);
    app(AttachFieldToProject::class)->handle($project, $field, $actor);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$task, $field, $actor, $project];
}

function setValue(Task $task, CustomField $field, User $actor, mixed $value): ?TaskCustomFieldValue
{
    return app(SetTaskCustomFieldValue::class)->handle($task, $field, $actor, $value);
}

/**
 * @return array{notifications: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function inbox(Workspace $workspace, User $reader, int $page = 1, int $perPage = 25): array
{
    return app(InboxQuery::class)($workspace, $reader, $page, $perPage);
}

/**
 * @return array{tasks: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function myTasks(Workspace $workspace, User $actor, MyTasksTab $tab = MyTasksTab::Today, int $page = 1, int $perPage = 25): array
{
    return app(MyTasksQuery::class)($workspace, $actor, $tab, $page, $perPage);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertFile(Workspace $workspace, ?User $uploader = null, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('files')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'uploaded_by' => $uploader?->id,
        'disk' => 'attachments',
        'path' => "workspaces/{$workspace->id}/".Str::uuid7(),
        'original_name' => 'quarterly plan.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 42_000,
        'checksum' => str_repeat('a', 64),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCustomField(Workspace $workspace, string $name, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('custom_fields')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'name' => $name,
        'type' => CustomFieldType::Text->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertOption(string $fieldId, string $label, int $position, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('custom_field_options')->insert([
        'id' => $id,
        'custom_field_id' => $fieldId,
        'label' => $label,
        'color' => null,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

/**
 * @return list<string>
 */
function queriesWhile(callable $work): array
{
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $work();

    return $queries;
}

/**
 * @param  list<string>  $statements
 * @param  callable(string): bool  $matches
 */
function indexOfStatement(array $statements, callable $matches): int
{
    foreach ($statements as $index => $sql) {
        if ($matches($sql)) {
            return $index;
        }
    }

    Assert::fail('No executed statement matched.');
}

/**
 * The suite's `null` broadcaster authorizes every subscription, so channels are registered on
 * Reverb, whose auth runs locally. `Broadcast::channel()` binds to the driver that was default
 * when the file first ran, hence the second require.
 *
 * @return array<string, Closure>
 */
function broadcastChannels(): array
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'channel-authorization-key',
        'broadcasting.connections.reverb.secret' => 'channel-authorization-secret',
        'broadcasting.connections.reverb.app_id' => 'channel-authorization-app',
    ]);

    require base_path('routes/channels.php');

    return Broadcast::driver()->getChannels()->all();
}

/**
 * The `{project}` route binding already refuses unseen projects, so only calling the callback
 * directly catches one that always returns `true`.
 */
function channelCallback(string $pattern): Closure
{
    $callback = broadcastChannels()[$pattern] ?? null;

    if (! $callback instanceof Closure) {
        throw new InvalidArgumentException("No channel is declared for [{$pattern}].");
    }

    return $callback;
}

/**
 * @param  list<array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function doc(array $content): array
{
    return ['type' => 'doc', 'content' => $content];
}

/**
 * @param  list<array<string, mixed>>  $marks
 * @return array<string, mixed>
 */
function textNode(string $text, array $marks = []): array
{
    return $marks === []
        ? ['type' => 'text', 'text' => $text]
        : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function mentionOf(User $user, ?string $name = null): string
{
    return '@['.($name ?? $user->name).'](user:'.$user->id.')';
}
