<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Domain\Activity\Queries\TaskFeedQuery;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

trait OpensTaskPanel
{
    use RemembersWhatWasOpened;

    protected function openTaskPanel(Request $request, Workspace $workspace, User $actor): ?Task
    {
        $id = $request->string('task')->value() ?: null;

        if ($id === null) {
            return null;
        }

        $task = $workspace->tasks()->whereKey($id)->first();

        // A 404 so a task out of reach is indistinguishable from one that does not exist.
        if (! $task instanceof Task || $actor->cannot('view', $task)) {
            abort(404);
        }

        $this->rememberOpening($workspace, $actor, $task);

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskPanelProps(
        Request $request,
        Workspace $workspace,
        User $actor,
        TaskDetailQuery $detail,
    ): array {
        $task = $this->openTaskPanel($request, $workspace, $actor);

        return [
            // Null rather than absent so a partial reload can tell "no panel" from "not requested".
            'taskDetail' => $task === null ? null : fn (): array => $detail($task, $actor),
            'activity' => $task === null
                ? null
                : Inertia::defer(fn (): array => app(TaskFeedQuery::class)($task, $actor)),
            'priorities' => array_column(TaskPriority::cases(), 'value'),
            'members' => fn (): array => $workspace->members()->orderBy('name')->get(PersonSummary::columns('users'))
                ->map(PersonSummary::from(...))
                ->values()
                ->all(),
        ];
    }
}
