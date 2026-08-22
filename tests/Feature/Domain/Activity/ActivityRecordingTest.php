<?php

declare(strict_types=1);

use App\Domain\Activity\Models\Activity;
use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\CompleteTask;
use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Models\Task;
use App\Providers\DomainEventServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('registers every domain listener explicitly, and discovers none', function (): void {
    /*
     * The map is the point: a listener nobody can see registering is one nobody can prove
     * runs, and the failure mode of discovery is silence.
     */
    expect(app(DomainEventServiceProvider::class, ['app' => app()])->shouldDiscoverEvents())->toBeFalse()
        ->and(Event::hasListeners(TaskCreated::class))->toBeTrue();
});

it('writes a line of history when a task is created', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    // The event itself, dispatched for real: asserting the listener is registered would prove
    // only that a class name is in an array.
    event(new TaskCreated($task->id, $workspace->id, $actor->id));

    $activity = Activity::query()->sole();

    expect($activity->type)->toBe(ActivityType::TaskCreated)
        ->and($activity->subject_type)->toBe('task')
        ->and($activity->subject_id)->toBe($task->id)
        ->and($activity->actor_id)->toBe($actor->id)
        ->and($activity->workspace_id)->toBe($workspace->id)
        ->and($activity->properties)->toBe([]);
});

it('records an edit as the fields that changed', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(fields: ['title'], title: 'Renamed'));

    $activity = Activity::query()->where('type', ActivityType::TaskUpdated)->sole();

    /*
     * The field names, not their values: a line that carried the old text would put a copy of
     * every task's description in this table.
     */
    expect($activity->properties)->toBe(['changed' => ['title']])
        ->and($activity->actor_id)->toBe($actor->id);
});

it('records completing and reopening as two separate things', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(CompleteTask::class)->complete($task, $actor);
    app(CompleteTask::class)->reopen($task, $actor);

    expect(Activity::query()->pluck('type')->all())
        ->toBe([ActivityType::TaskCompleted, ActivityType::TaskReopened]);
});

it('records who a task was given to, and that it was taken back', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    app(AssignTask::class)->handle($task, $actor, $assignee);
    app(AssignTask::class)->handle($task, $actor, null);

    // Null is why the property is carried at all: unassigning is something that happened, and
    // a line omitting the field would be indistinguishable from one nobody recorded.
    expect(Activity::query()->where('type', ActivityType::TaskAssigned)->orderBy('created_at')->pluck('properties')->all())
        ->toBe([['assignee_id' => $assignee->id], ['assignee_id' => null]]);
});

it('records a task arriving in a project and leaving it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    app(AttachTaskToProject::class)->handle($task, $project, $actor);
    app(DetachTaskFromProject::class)->handle($task, $project, $actor);

    /*
     * The placement events carry no workspace, so the listener reads the task for one rather
     * than the event being widened for a listener's convenience — and the row still has to be
     * scoped correctly.
     */
    $activities = Activity::query()->whereIn('type', [
        ActivityType::TaskAttachedToProject,
        ActivityType::TaskDetachedFromProject,
    ])->orderBy('created_at')->get();

    expect($activities->pluck('properties')->all())
        ->toBe([['project_id' => $project->id], ['project_id' => $project->id]])
        ->and($activities->pluck('workspace_id')->unique()->all())->toBe([$workspace->id]);
});

it('writes ids and values, never a serialised model', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Original']);

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(fields: ['title'], title: 'Renamed'));

    /*
     * A feed line rendered from a snapshot would show a name that has since changed as though
     * it never did — and would keep a copy of it in a table nobody thinks of as holding task
     * text.
     */
    $properties = (string) DB::table('activities')->where('type', 'task.updated')->value('properties');

    expect($properties)->not->toContain('Original')
        ->and($properties)->not->toContain('Renamed');
});

it('says nothing when nothing changed', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Original']);

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(fields: ['title'], title: 'Original'));

    // No event, so no line: history records what happened, and nothing did.
    expect(Activity::query()->count())->toBe(0);
});
