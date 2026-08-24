<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Domain\Shared\Enums\TaskPriority;
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
    /**
     * The task whose panel is open, if the URL names one the actor may read.
     *
     * Resolved inside the workspace and then through the policy, exactly as `tasks.show` does.
     * A 404 rather than a redirect or an empty panel: an id that names a task out of reach must
     * be answered the same way as an id that names nothing, or the difference between the two
     * answers is itself the leak.
     *
     * @return array<string, mixed>|null
     */
    protected function openTaskPanel(Request $request, Workspace $workspace, User $actor, TaskDetailQuery $detail): ?array
    {
        $id = $request->string('task')->value() ?: null;

        if ($id === null) {
            return null;
        }

        $task = $workspace->tasks()->whereKey($id)->first();

        if (! $task instanceof Task || $actor->cannot('view', $task)) {
            abort(404);
        }

        return $detail($task, $actor);
    }

    /**
     * The props the panel needs, on any screen that can open one.
     *
     * `taskDetail` is `null` rather than absent so a partial reload can tell "no panel" from
     * "not sent this time". `activity` is deferred and only when there is a panel — the same
     * region the task's own page defers (TASK-100-011).
     *
     * @param  array<string, mixed>|null  $open
     * @return array<string, mixed>
     */
    protected function taskPanelProps(Workspace $workspace, ?array $open): array
    {
        return [
            'taskDetail' => $open,
            'activity' => $open === null ? null : Inertia::defer(fn (): array => []),
            'priorities' => array_column(TaskPriority::cases(), 'value'),
            'members' => $workspace->members()->orderBy('name')->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => null,
                ])
                ->values()
                ->all(),
        ];
    }
}
