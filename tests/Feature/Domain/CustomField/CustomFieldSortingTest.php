<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Data\FieldSort;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * A project with one field on it, and a helper that puts answered tasks in it.
 *
 * @param  list<string>  $options
 * @return array{Project, CustomField, User}
 */
function projectWithAField(CustomFieldType $type, array $options = []): array
{
    [$workspace, $project, $actor] = placeableProject(role: WorkspaceRole::Owner);

    $field = app(DefineCustomField::class)->handle($workspace, $actor, 'Estimate', $type, $options);
    app(AttachFieldToProject::class)->handle($project, $field, $actor);

    return [$project, $field, $actor];
}

function answeredCard(Project $project, CustomField $field, User $actor, string $title, mixed $answer, int $slot): Task
{
    $task = Task::factory()->in($project->workspace)->create(['title' => $title]);
    TaskProjectMembership::factory()->placing($task, $project)->at($slot * SparsePosition::GAP)->create();

    if ($answer !== null) {
        setValue($task, $field, $actor, $answer);
    }

    return $task;
}

/**
 * @param  array{sections: list<array{tasks: list<array<string, mixed>>}>}  $result
 * @return list<string>
 */
function listedTitles(array $result): array
{
    return array_column($result['sections'][0]['tasks'], 'title');
}

it('sorts numbers numerically rather than as text', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    answeredCard($project, $field, $actor, 'Nine', '9', 1);
    answeredCard($project, $field, $actor, 'Ten', '10', 2);
    answeredCard($project, $field, $actor, 'Two', '2', 3);

    /*
     * As text, "10" sorts before "2" — which is the entire reason the values live in typed
     * columns rather than in a json document.
     */
    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field))))
        ->toBe(['Two', 'Nine', 'Ten']);
});

it('sorts dates chronologically', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Date);

    answeredCard($project, $field, $actor, 'Later', '2026-12-01', 1);
    answeredCard($project, $field, $actor, 'Sooner', '2026-01-05', 2);

    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field))))
        ->toBe(['Sooner', 'Later']);
});

it('sorts the other way round when asked', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    answeredCard($project, $field, $actor, 'Small', '2', 1);
    answeredCard($project, $field, $actor, 'Large', '20', 2);

    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field, descending: true))))
        ->toBe(['Large', 'Small']);
});

it('puts the unanswered rows last, whichever way it sorts', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    answeredCard($project, $field, $actor, 'Answered', '5', 1);
    answeredCard($project, $field, $actor, 'Blank', null, 2);

    /*
     * A row nobody has answered is not the smallest value, it is an absence — and burying it at
     * the top of an ascending list is how a column of blanks becomes the first thing anybody
     * sees.
     */
    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field))))
        ->toBe(['Answered', 'Blank'])
        ->and(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field, descending: true))))
        ->toBe(['Answered', 'Blank']);
});

it('keeps the order somebody put them in when two rows answer the same', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    answeredCard($project, $field, $actor, 'First', '5', 1);
    answeredCard($project, $field, $actor, 'Second', '5', 2);

    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], new FieldSort($field))))
        ->toBe(['First', 'Second']);
});

it('filters to the rows carrying one answer', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Text);

    answeredCard($project, $field, $actor, 'Matching', 'Two days', 1);
    answeredCard($project, $field, $actor, 'Other', 'Three days', 2);
    answeredCard($project, $field, $actor, 'Blank', null, 3);

    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], null, [$field->id => 'Two days'])))
        ->toBe(['Matching']);
});

it('filters a choice by the option it names', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Select, ['Draft', 'Done']);
    $draft = $field->options()->first();

    answeredCard($project, $field, $actor, 'Drafted', $draft?->id, 1);
    answeredCard($project, $field, $actor, 'Nothing yet', null, 2);

    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], null, [$field->id => (string) $draft?->id])))
        ->toBe(['Drafted']);
});

it('renders the list when a link names a field the project no longer shows', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Text);
    answeredCard($project, $field, $actor, 'Still here', 'Two days', 1);

    // A stale link renders the list rather than an error, the way a deleted tag does.
    expect(listedTitles(app(ProjectListQuery::class)($project, $actor, [], null, [(string) Str::uuid7() => 'anything'])))
        ->toBe(['Still here']);
});

it('filters in the database, on the index the values table was built for', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    /*
     * Three thousand answers across thirty fields, `ANALYZE`d, so the planner is choosing from
     * statistics. An index the planner ignores is not an index the query has (TASK-070-002).
     */
    $rows = [];

    // A hundred tasks, reused across thirty fields: the pair is what has to be unique, and
    // three thousand separate tasks would test the factory rather than the index.
    $tasks = Task::factory()->in($project->workspace)->count(100)->create()->pluck('id')->all();

    foreach (range(1, 30) as $index) {
        $other = CustomField::factory()->in($project->workspace)->ofType(CustomFieldType::Number)->create();

        foreach ($tasks as $answer => $taskId) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'task_id' => $taskId,
                'custom_field_id' => $index === 1 ? $field->id : $other->id,
                'value_text' => null,
                'value_number' => $answer + 1,
                'value_date' => null,
                'value_boolean' => null,
                'value_option_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('task_custom_field_values')->insert($chunk);
    }

    DB::statement('ANALYZE task_custom_field_values');

    $query = DB::table('task_custom_field_values')
        ->where('custom_field_id', $field->id)
        ->where('value_number', 42);

    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];
    $plan = (string) json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    expect($plan)->toContain('task_custom_field_values_custom_field_id_value_number_index')
        ->and($plan)->not->toContain('Seq Scan');
});

it('carries the ordering in the URL and echoes back what it understood', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);
    answeredCard($project, $field, $actor, 'Larger', '20', 1);
    answeredCard($project, $field, $actor, 'Smaller', '2', 2);

    $this->actingAs($actor)
        ->get(route('projects.show', [
            $project,
            'view' => 'list',
            'sort' => $field->id,
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sort.field', $field->id)
            ->where('sort.direction', 'desc')
            ->where('list.sections.0.tasks.0.title', 'Larger'));
});

it('filters from the URL too', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Text);
    answeredCard($project, $field, $actor, 'Matching', 'Two days', 1);
    answeredCard($project, $field, $actor, 'Other', 'Three days', 2);

    $this->actingAs($actor)
        ->get(route('projects.show', [
            $project,
            'view' => 'list',
            'field' => [$field->id => 'Two days'],
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('list.sections.0.tasks', 1)
            ->where('list.sections.0.tasks.0.title', 'Matching'));
});

it('orders by nothing when the link names a field that is gone', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);
    answeredCard($project, $field, $actor, 'First', '20', 1);
    answeredCard($project, $field, $actor, 'Second', '2', 2);

    // A stale ordering falls back to the order somebody put the cards in, rather than 404ing.
    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'list', 'sort' => (string) Str::uuid7()]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sort.field', null)
            ->where('list.sections.0.tasks.0.title', 'First'));
});

it('refuses a direction that is not one', function (): void {
    [$project, $field, $actor] = projectWithAField(CustomFieldType::Number);

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'sort' => $field->id, 'direction' => 'sideways']))
        ->assertSessionHasErrors('direction');
});
