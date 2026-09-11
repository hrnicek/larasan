<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Concerns\OpensTaskPanel;
use App\Domain\Notification\Actions\MarkInboxRead;
use App\Domain\Notification\Actions\MarkNotificationRead;
use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is waiting for this person in the workspace they are standing in.
 *
 * The page is a query parameter for the same reason My Tasks' is: a link has to carry what it
 * was showing, and a refresh has to land back on it.
 */
class InboxController extends Controller
{
    use OpensTaskPanel;

    public function index(Request $request, InboxQuery $inbox, TaskDetailQuery $detail): Response
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $page = max(1, (int) $request->query('page', '1'));
        $actor = $this->actor($request);

        $inboxPage = $this->memoized(fn (): array => $inbox($workspace, $actor, $page));

        return Inertia::render('inbox/Index', [
            /*
             * Two keys of one read, each a closure: the panel opening reads no notifications at
             * all, and a reload that asks for both reads them once.
             */
            'notifications' => fn (): array => $inboxPage()['notifications'],
            'meta' => fn (): array => $inboxPage()['meta'],
            /*
             * A line that leads to a task opens it here rather than sending somebody away from
             * the list they are working through — which is the whole reason the panel has an
             * address of its own.
             */
            ...$this->taskPanelProps($request, $workspace, $actor, $detail),
        ]);
    }

    /**
     * Read state is the server's answer, not the client's guess (`docs/ui/inbox.md`): the screen
     * asks, and re-renders from what comes back.
     */
    public function read(Request $request, DatabaseNotification $notification, MarkNotificationRead $markRead): RedirectResponse
    {
        $markRead->handle($notification);

        return back();
    }

    public function readAll(Request $request, MarkInboxRead $markInboxRead): RedirectResponse
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $cleared = $markInboxRead->handle($workspace, $this->actor($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('Marked :count notification read|Marked :count notifications read', $cleared, ['count' => $cleared]),
        ]);

        return back();
    }
}
