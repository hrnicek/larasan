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

/**
 * The task detail panel, for the four screens that can open one.
 *
 * The panel is an address — `?task=` on whichever screen it was opened from — so every screen
 * that offers it answers the same three props. Written once because the rule underneath them is
 * a security rule, and a security rule copied four times is a security rule that will differ in
 * one of them: a panel is not a way around the fact that a task in a project somebody was never
 * given is not theirs to read (TASK-070-017).
 */
trait OpensTaskPanel
{
    use RemembersWhatWasOpened;

    /**
     * The task whose panel is open, if the URL names one the actor may read.
     *
     * Resolved inside the workspace and then through the policy, exactly as `tasks.show` does.
     * A 404 rather than a redirect or an empty panel: an id that names a task out of reach must
     * be answered the same way as an id that names nothing, or the difference between the two
     * answers is itself the leak.
     */
    protected function openTaskPanel(Request $request, Workspace $workspace, User $actor): ?Task
    {
        $id = $request->string('task')->value() ?: null;

        if ($id === null) {
            return null;
        }

        $task = $workspace->tasks()->whereKey($id)->first();

        if (! $task instanceof Task || $actor->cannot('view', $task)) {
            abort(404);
        }

        $this->rememberOpening($workspace, $actor, $task);

        return $task;
    }

    /**
     * The props the panel needs, on any screen that can open one.
     *
     * `taskDetail` is `null` rather than absent so a partial reload can tell "no panel" from
     * "not sent this time". `activity` is deferred and only when there is a panel — the same
     * region the task's own page defers (TASK-100-011).
     *
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
            'taskDetail' => $task === null ? null : $detail($task, $actor),
            /*
             * The task's history and its conversation, deferred — the same region the task's own
             * page defers, answered by the same query. It was a stub returning `[]`, so a panel
             * opened from a list showed an empty thread however much had been said on the task.
             */
            'activity' => $task === null
                ? null
                : Inertia::defer(fn (): array => app(TaskFeedQuery::class)($task, $actor)),
            'priorities' => array_column(TaskPriority::cases(), 'value'),
            'members' => $workspace->members()->orderBy('name')->get()
                ->map(PersonSummary::from(...))
                ->values()
                ->all(),
        ];
    }
}
