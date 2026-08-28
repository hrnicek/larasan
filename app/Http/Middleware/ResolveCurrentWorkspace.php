<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentWorkspace
{
    public const ATTRIBUTE = 'current_workspace';

    public function __construct(private readonly CurrentWorkspace $workspaces) {}

    /**
     * The `workspace` route parameter is a **slug**, not an id. Routes that key a
     * workspace by id resolve to nothing here and 404 before their controller or policy
     * runs — which is the right answer for an unknown slug and a confusing one for a
     * misrouted id, so the convention is stated rather than discovered.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $slug = $request->route('workspace');
        $workspace = $this->workspaces->for($user, is_string($slug) ? $slug : null);

        /*
         * A workspace the actor has no active membership for is indistinguishable from
         * one that does not exist. 403 would confirm the id, which is what makes a leaked
         * UUID worth something.
         */
        if ($workspace === null && is_string($slug)) {
            abort(404);
        }

        if ($workspace instanceof Workspace) {
            $request->attributes->set(self::ATTRIBUTE, $workspace);

            if ($user->current_workspace_id !== $workspace->id) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }
        }

        return $next($request);
    }

    public static function from(Request $request): ?Workspace
    {
        $workspace = $request->attributes->get(self::ATTRIBUTE);

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
