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
use Closure;
use Illuminate\Http\Request;
use Inertia\DeferProp;
use Inertia\Inertia;
use Inertia\Support\Header;

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

        if ($this->resolvesProp($request, 'taskDetail')) {
            $this->rememberOpening($workspace, $actor, $task);
        }

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
            'activity' => $task === null ? null : $this->taskActivity($task, $actor),
            ...$this->taskControlProps($workspace, $actor),
        ];
    }

    protected function taskActivity(Task $task, User $actor): DeferProp
    {
        return Inertia::defer(fn (): array => app(TaskFeedQuery::class)($task, $actor));
    }

    /**
     * @return array{priorities: array<int, string>, members: Closure(): array<int, array<string, mixed>>}
     */
    protected function taskControlProps(Workspace $workspace, User $actor): array
    {
        return [
            'priorities' => array_column(TaskPriority::cases(), 'value'),
            'members' => fn (): array => $workspace->members()->orderBy('name')->get(PersonSummary::columns('users'))
                ->map(PersonSummary::for($workspace, $actor)->of(...))
                ->values()
                ->all(),
        ];
    }

    /**
     * Realtime refreshes reload the screen beneath an open panel with `?task=` still in the address.
     */
    protected function resolvesProp(Request $request, string $prop): bool
    {
        if (! $request->hasHeader(Header::PARTIAL_COMPONENT)) {
            return true;
        }

        $only = array_filter(explode(',', (string) $request->headers->get(Header::PARTIAL_ONLY)));
        $except = array_filter(explode(',', (string) $request->headers->get(Header::PARTIAL_EXCEPT)));

        return ($only === [] || in_array($prop, $only, true)) && ! in_array($prop, $except, true);
    }
}
