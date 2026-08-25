<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Database\Seeders\HeavySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The seeder runs at a fraction of its real scale here — the invariants it has to hold are
 * the same at forty tasks as at six thousand, and a suite that seeded six thousand would be
 * paying a minute a run to prove it twice.
 */
function seedHeavily(): void
{
    // Bound rather than constructed: `db:seed` resolves the class from the container and
    // hands it the console command it reports through, so the seeder runs here exactly as
    // it runs from the terminal — only smaller.
    app()->bind(HeavySeeder::class, fn (): HeavySeeder => new HeavySeeder(peopleCount: 8, projectCount: 4, taskCount: 60));

    Artisan::call('db:seed', ['--class' => HeavySeeder::class]);
}

it('fills a workspace with a year of work', function (): void {
    seedHeavily();

    $workspace = Workspace::query()->where('name', 'Northwind')->firstOrFail();

    expect($workspace->projects()->count())->toBe(4)
        ->and(Task::query()->withTrashed()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(40)
        ->and($workspace->memberships()->count())->toBe(8);

    $oldest = Task::query()->withTrashed()->orderBy('created_at')->firstOrFail();

    expect($oldest->created_at?->lessThan(now()->subMonths(9)))->toBeTrue();
});

it('gives every project the owner membership its owner column claims', function (): void {
    seedHeavily();

    Project::query()->with('memberships')->get()->each(function (Project $project): void {
        $owner = $project->memberships->firstWhere('user_id', $project->owner_id);

        expect($owner?->access_level)->toBe(ProjectAccessLevel::Owner);
    });
});

it('assigns work only to people who are actually in the workspace', function (): void {
    seedHeavily();

    $workspace = Workspace::query()->where('name', 'Northwind')->firstOrFail();

    $members = $workspace->memberships()
        ->where('status', WorkspaceMembershipStatus::Active)
        ->pluck('user_id')
        ->all();

    $assignees = Task::query()->withTrashed()->whereNotNull('assignee_id')->pluck('assignee_id')->unique();

    expect($assignees)->not->toBeEmpty()
        ->and($assignees->diff($members)->all())->toBe([]);
});

it('writes a completion the way the Action writes one', function (): void {
    seedHeavily();

    $completed = Task::query()->withTrashed()->whereNotNull('completed_at')->get();

    expect($completed)->not->toBeEmpty();

    $completed->each(fn (Task $task) => expect($task->completed_by)->not->toBeNull());

    expect(Task::query()->withTrashed()->whereNull('completed_at')->whereNotNull('completed_by')->count())->toBe(0);
});

it('places each card in one slot of one column', function (): void {
    seedHeavily();

    $slots = TaskProjectMembership::query()
        ->selectRaw('project_id, section_id, position, count(*) as total')
        ->groupBy('project_id', 'section_id', 'position')
        ->havingRaw('count(*) > 1')
        ->count();

    expect($slots)->toBe(0)
        ->and(TaskProjectMembership::query()->whereNotNull('section_id')->count())->toBeGreaterThan(0)
        ->and(TaskProjectMembership::query()->whereNull('section_id')->count())->toBeGreaterThan(0);
});

it('never places a task in a project belonging to another workspace', function (): void {
    seedHeavily();

    $crossing = DB::table('task_project_memberships')
        ->join('tasks', 'tasks.id', '=', 'task_project_memberships.task_id')
        ->join('projects', 'projects.id', '=', 'task_project_memberships.project_id')
        ->whereColumn('tasks.workspace_id', '!=', 'projects.workspace_id')
        ->count();

    expect($crossing)->toBe(0);
});

it('lets nobody comment who may only read', function (): void {
    seedHeavily();

    $viewers = DB::table('comments')
        ->join('task_project_memberships', 'task_project_memberships.task_id', '=', 'comments.commentable_id')
        ->join('project_memberships', function ($join): void {
            $join->on('project_memberships.project_id', '=', 'task_project_memberships.project_id')
                ->on('project_memberships.user_id', '=', 'comments.author_id');
        })
        ->where('project_memberships.access_level', ProjectAccessLevel::Viewer->value)
        ->count();

    expect($viewers)->toBe(0)
        ->and(DB::table('comments')->count())->toBeGreaterThan(0);
});

it('leaves the second run alone rather than doubling the workspace', function (): void {
    seedHeavily();

    $counts = [
        'projects' => DB::table('projects')->count(),
        'tasks' => DB::table('tasks')->count(),
        'activities' => DB::table('activities')->count(),
    ];

    seedHeavily();

    expect(DB::table('projects')->count())->toBe($counts['projects'])
        ->and(DB::table('tasks')->count())->toBe($counts['tasks'])
        ->and(DB::table('activities')->count())->toBe($counts['activities']);
});

it('refuses to run in production, where the password would be a way in', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => Artisan::call('db:seed', ['--class' => HeavySeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'refused in production');
})->after(fn () => app()->detectEnvironment(fn (): string => 'testing'));
