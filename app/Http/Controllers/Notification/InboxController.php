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
            'notifications' => fn (): array => $inboxPage()['notifications'],
            'meta' => fn (): array => $inboxPage()['meta'],
            ...$this->taskPanelProps($request, $workspace, $actor, $detail),
        ]);
    }

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
