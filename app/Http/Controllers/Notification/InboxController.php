<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Domain\Notification\Actions\MarkNotificationRead;
use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Models\User;
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
    public function index(Request $request, InboxQuery $inbox): Response
    {
        $workspace = ResolveCurrentWorkspace::from($request);

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $page = max(1, (int) $request->query('page', '1'));

        return Inertia::render('inbox/Index', $inbox($workspace, $this->actor($request), $page));
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

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
