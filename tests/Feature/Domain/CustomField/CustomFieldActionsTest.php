<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\DeleteCustomField;
use App\Domain\CustomField\Actions\DetachFieldFromProject;
use App\Domain\CustomField\Actions\RenameCustomField;
use App\Domain\CustomField\Actions\SetTaskCustomFieldValue;
use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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

it('defines a field with its choices in one go', function (): void {
    [$workspace, , $actor] = placeableProject(role: WorkspaceRole::Owner);

    $field = app(DefineCustomField::class)->handle($workspace, $actor, '  Stage  ', CustomFieldType::Select, ['Draft', 'Review', 'Done']);

    expect($field->name)->toBe('Stage')
        ->and($field->options()->pluck('label')->all())->toBe(['Draft', 'Review', 'Done']);
});

it('refuses a choice field with nothing to choose from', function (): void {
    [$workspace, , $actor] = placeableProject(role: WorkspaceRole::Owner);

    expect(fn (): CustomField => app(DefineCustomField::class)->handle($workspace, $actor, 'Stage', CustomFieldType::Select))
        ->toThrow(CustomFieldException::class, 'A choice field needs at least one choice.');

    expect(CustomField::query()->count())->toBe(0);
});

it('refuses a duplicate name whatever case it is typed in', function (): void {
    [$workspace, , $actor] = placeableProject(role: WorkspaceRole::Owner);
    app(DefineCustomField::class)->handle($workspace, $actor, 'Estimate', CustomFieldType::Number);

    expect(fn (): CustomField => app(DefineCustomField::class)->handle($workspace, $actor, 'ESTIMATE', CustomFieldType::Text))
        ->toThrow(CustomFieldException::class, 'A field with that name already exists.');
});

it('refuses a member without custom_field.manage, at every definition operation', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $owner = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    expect(fn (): CustomField => app(DefineCustomField::class)->handle($workspace, $member, 'Risk', CustomFieldType::Text))
        ->toThrow(CustomFieldException::class, 'You do not have permission to manage custom fields in this workspace.');

    expect(fn (): CustomField => app(RenameCustomField::class)->handle($field, $member, 'Renamed'))
        ->toThrow(CustomFieldException::class);
    expect(fn () => app(AttachFieldToProject::class)->handle($project, $field, $member))
        ->toThrow(CustomFieldException::class);
    expect(fn () => app(DeleteCustomField::class)->handle($field, $member))
        ->toThrow(CustomFieldException::class);
});

it('shows a field on a project once, at the end', function (): void {
    [$workspace, $project, $actor] = placeableProject(role: WorkspaceRole::Owner);
    $first = app(DefineCustomField::class)->handle($workspace, $actor, 'Estimate', CustomFieldType::Number);
    $second = app(DefineCustomField::class)->handle($workspace, $actor, 'Risk', CustomFieldType::Text);

    app(AttachFieldToProject::class)->handle($project, $first, $actor);
    app(AttachFieldToProject::class)->handle($project, $second, $actor);
    app(AttachFieldToProject::class)->handle($project, $first, $actor);

    expect($project->customFields()->pluck('name')->all())->toBe(['Estimate', 'Risk']);
});

it('refuses a field from another workspace', function (): void {
    [, $project, $actor] = placeableProject(role: WorkspaceRole::Owner);
    $elsewhere = CustomField::factory()->create();

    expect(fn () => app(AttachFieldToProject::class)->handle($project, $elsewhere, $actor))
        ->toThrow(CustomFieldException::class, 'That field is not in this workspace.');
});

it('keeps the answers when a field stops being shown', function (): void {
    [$task, $field, $actor, $project] = fieldOnATask();
    setValue($task, $field, $actor, 'Two days');

    app(DetachFieldFromProject::class)->handle($project, $field, $actor);

    expect(TaskCustomFieldValue::query()->count())->toBe(1);

    app(AttachFieldToProject::class)->handle($project, $field, $actor);

    expect($task->customFieldValues()->sole()->value($field))->toBe('Two days');
});

it('removes the answers when the field itself goes', function (): void {
    [$task, $field, $actor] = fieldOnATask();
    setValue($task, $field, $actor, 'Two days');

    app(DeleteCustomField::class)->handle($field, $actor);

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
});

it('writes an answer into the column its type says', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Number);

    $answer = setValue($task, $field, $actor, '12.5');

    expect($answer?->value($field))->toEqual(12.5)
        ->and($answer?->value_text)->toBeNull();
});

it('replaces an answer rather than adding a second', function (): void {
    [$task, $field, $actor] = fieldOnATask();

    setValue($task, $field, $actor, 'Two days');
    setValue($task, $field, $actor, 'Three days');

    expect($task->customFieldValues()->count())->toBe(1)
        ->and($task->customFieldValues()->sole()->value($field))->toBe('Three days');
});

it('removes the row when an answer is cleared', function (): void {
    [$task, $field, $actor] = fieldOnATask();
    setValue($task, $field, $actor, 'Two days');

    expect(setValue($task, $field, $actor, null))->toBeNull();

    expect($task->customFieldValues()->count())->toBe(0);
});

it('accepts only this field s own choices', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Select, ['Draft', 'Done']);
    $strangerOption = CustomFieldOption::factory()->create();

    expect(fn (): ?TaskCustomFieldValue => setValue($task, $field, $actor, $strangerOption->id))
        ->toThrow(CustomFieldException::class, 'That choice does not belong to this field.');

    $own = $field->options()->first();

    expect(setValue($task, $field, $actor, $own?->id)?->value($field))->toBe($own?->id);
});

it('refuses a field the task s projects do not show', function (): void {
    [$task, , $actor] = fieldOnATask();
    $workspace = $task->workspace;
    $unattached = app(DefineCustomField::class)->handle($workspace, $actor, 'Risk', CustomFieldType::Text);

    expect(fn (): ?TaskCustomFieldValue => setValue($task, $unattached, $actor, 'High'))
        ->toThrow(CustomFieldException::class, 'That field is not shown on this task.');
});

it('refuses a value from somebody who may not edit the task', function (): void {
    [$task, $field] = fieldOnATask();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);

    expect(fn (): ?TaskCustomFieldValue => setValue($task, $field, $guest, 'Two days'))
        ->toThrow(CustomFieldException::class, 'You do not have permission to change this task.');
});

it('refuses a value on a task in another workspace', function (): void {
    [$task, , $actor] = fieldOnATask();
    $elsewhere = CustomField::factory()->create();

    expect(fn (): ?TaskCustomFieldValue => setValue($task, $elsewhere, $actor, 'Two days'))
        ->toThrow(CustomFieldException::class, 'That field is not in this workspace.');
});

it('carries a project s fields as columns and each row s answers', function (): void {
    [$task, $field, $actor, $project] = fieldOnATask(CustomFieldType::Number);
    setValue($task, $field, $actor, '12.5');

    $untouched = Task::factory()->in($task->workspace)->create();
    TaskProjectMembership::factory()->placing($untouched, $project)->at(2 * SparsePosition::GAP)->create();

    $list = app(ProjectListQuery::class)($project, $actor);

    expect(array_column($list['fields'], 'name'))->toBe(['Estimate'])
        ->and($list['sections'][0]['tasks'][0]['fields'])->toBe([$field->id => 12.5])
        ->and($list['sections'][0]['tasks'][1]['fields'])->toBe([]);
});

it('reads a list of answered rows without a query per row', function (): void {
    [$task, $field, $actor, $project] = fieldOnATask(CustomFieldType::Text);
    setValue($task, $field, $actor, 'First');

    foreach (range(2, 10) as $index) {
        $other = Task::factory()->in($task->workspace)->create();
        TaskProjectMembership::factory()->placing($other, $project)->at($index * SparsePosition::GAP)->create();
        setValue($other, $field, $actor, "Answer {$index}");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $list = app(ProjectListQuery::class)($project, $actor);

    expect($list['sections'][0]['tasks'])->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(10);
});

it('leaves a project with no fields exactly as it was', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $list = app(ProjectListQuery::class)($project, $actor);

    expect($list['fields'])->toBe([])
        ->and($list['sections'][0]['tasks'][0]['fields'])->toBe([]);
});
