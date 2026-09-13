<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Gate;

it('forgets what it knew about a task once the task moves under another parent', function (): void {
    $workspace = Workspace::factory()->create();
    $colleague = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    $secret = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($secret, $private)->create();
    $task = Task::factory()->in($workspace)->create();

    expect(Gate::forUser($colleague)->allows('view', $task))->toBeTrue();

    $task->forceFill(['parent_id' => $secret->id])->save();

    expect(Gate::forUser($colleague)->allows('view', $task))->toBeFalse();
});
