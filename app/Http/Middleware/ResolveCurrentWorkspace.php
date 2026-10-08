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
     * The `workspace` route parameter is a slug; a route keyed by id resolves nothing and 404s.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $slug = $request->route('workspace');
        $workspace = $this->workspaces->for($user, is_string($slug) ? $slug : null);

        // 404 rather than 403, so an inaccessible workspace looks the same as a missing one.
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
