<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertTask(Workspace $workspace, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('tasks')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'parent_id' => null,
        'title' => 'Write the migration',
        'description' => null,
        'priority' => TaskPriority::Medium->value,
        'due_at' => null,
        'completed_at' => null,
        'completed_by' => null,
        'assignee_id' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('carries no placement columns at all', function (): void {
    expect(Schema::hasColumn('tasks', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'section_id'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'position'))->toBeFalse();
});

it('refuses a priority the domain does not define', function (): void {
    expect(fn (): string => insertTask(Workspace::factory()->create(), ['priority' => 'whenever']))
        ->toThrow(QueryException::class);
});

it('defaults a new task to medium priority and open', function (): void {
    $workspace = Workspace::factory()->create();
    $id = (string) Str::uuid7();

    DB::table('tasks')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'title' => 'Untouched',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $task = DB::table('tasks')->where('id', $id)->first();

    expect($task?->priority)->toBe(TaskPriority::Medium->value)
        ->and($task?->completed_at)->toBeNull()
        ->and($task?->deleted_at)->toBeNull();
});

it('deletes its tasks with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertTask($workspace);

    $workspace->delete();

    expect(DB::table('tasks')->count())->toBe(0);
});

it('keeps a subtask when its parent is deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $parent = insertTask($workspace, ['title' => 'Parent']);
    $child = insertTask($workspace, ['title' => 'Child', 'parent_id' => $parent]);

    DB::table('tasks')->where('id', $parent)->delete();

    $subtask = DB::table('tasks')->where('id', $child)->first();

    expect($subtask)->not->toBeNull()
        ->and($subtask?->parent_id)->toBeNull();
});

it('keeps a task when the people on it close their accounts', function (): void {
    $workspace = Workspace::factory()->create();
    $assignee = User::factory()->create();
    $creator = User::factory()->create();
    $id = insertTask($workspace, [
        'assignee_id' => $assignee->id,
        'created_by' => $creator->id,
        'completed_by' => $creator->id,
        'completed_at' => now(),
    ]);

    $assignee->delete();
    $creator->delete();

    $task = DB::table('tasks')->where('id', $id)->first();

    expect($task)->not->toBeNull()
        ->and($task?->assignee_id)->toBeNull()
        ->and($task?->created_by)->toBeNull()
        ->and($task?->completed_by)->toBeNull()
        ->and($task?->completed_at)->not->toBeNull();
});

it('refuses a parent from another workspace only in the domain, not the database', function (): void {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    $foreignParent = insertTask($theirs);

    // A self-referencing foreign key cannot enforce the same workspace; the Action refuses it instead.
    $id = insertTask($mine, ['parent_id' => $foreignParent]);

    expect(DB::table('tasks')->where('id', $id)->exists())->toBeTrue();
});

it("indexes one person's open work in one workspace", function (): void {
    $indexes = collect(Schema::getIndexes('tasks'))->pluck('columns');

    expect($indexes)->toContain(['workspace_id', 'assignee_id', 'completed_at']);
});

it('computes the search vector with an empty search path, as a restore runs it', function (): void {
    $workspace = Workspace::factory()->create();
    $id = (string) Str::uuid7();

    $vector = DB::transaction(function () use ($workspace, $id): string {
        DB::statement("SET LOCAL search_path = ''");

        DB::insert(
            'insert into public.tasks (id, workspace_id, title, created_at, updated_at) values (?, ?, ?, now(), now())',
            [$id, $workspace->id, 'Café crème'],
        );

        $vector = (string) DB::scalar('select search_vector::text from public.tasks where id = ?', [$id]);

        DB::statement('SET LOCAL search_path = public');

        return $vector;
    });

    expect($vector)->toContain("'cafe'")
        ->and($vector)->toContain("'creme'");
});

it('strips accents with an empty search path', function (): void {
    $unaccented = DB::transaction(function (): string {
        DB::statement("SET LOCAL search_path = ''");

        $unaccented = (string) DB::scalar("select public.immutable_unaccent('é')");

        DB::statement('SET LOCAL search_path = public');

        return $unaccented;
    });

    expect($unaccented)->toBe('e');
});
