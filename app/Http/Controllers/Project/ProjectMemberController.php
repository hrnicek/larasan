<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\GrantProjectAccess;
use App\Domain\Project\Actions\RevokeProjectAccess;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\GrantProjectAccessRequest;
use App\Http\Requests\Project\UpdateProjectAccessRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Who may reach this project, and what they may do here.
 *
 * Access inside a project is not the same question as membership of the workspace (ADR-0006), so
 * these are the project's own routes rather than a variant of `WorkspaceMemberController`: adding
 * somebody here gives them a level in one project and changes nothing about the workspace.
 */
class ProjectMemberController extends Controller
{
    public function store(
        GrantProjectAccessRequest $request,
        Project $project,
        GrantProjectAccess $grantAccess,
    ): RedirectResponse {
        $member = User::query()->whereKey($request->input('user'))->first() ?? abort(404);

        $grantAccess->handle(
            $project,
            $this->actor($request),
            $member,
            ProjectAccessLevel::from((string) $request->string('access_level')),
        );

        return back();
    }

    public function update(
        UpdateProjectAccessRequest $request,
        Project $project,
        string $membership,
        GrantProjectAccess $grantAccess,
    ): RedirectResponse {
        // Granting and changing are the same sentence — *this person has this access here* — so
        // they are the same Action, reached by a different verb.
        $grantAccess->handle(
            $project,
            $this->actor($request),
            $this->membership($project, $membership)->user,
            ProjectAccessLevel::from((string) $request->string('access_level')),
        );

        return back();
    }

    public function destroy(
        Request $request,
        Project $project,
        string $membership,
        RevokeProjectAccess $revokeAccess,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $project);

        $revokeAccess->handle($project, $this->actor($request), $this->membership($project, $membership));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Access removed. What they wrote stays.'),
        ]);

        return back();
    }

    /**
     * Resolved inside the project the route already proved the actor may reach, never by implicit
     * binding: a membership id from another project would otherwise resolve by primary key alone,
     * and a leaked id would be worth something. A 404 rather than a 403, because confirming the
     * other id exists is not something the actor is entitled to.
     *
     * `WorkspaceMemberController` resolves its own the same way and for the same reason.
     */
    private function membership(Project $project, string $id): ProjectMembership
    {
        return $project->memberships()->whereKey($id)->first() ?? abort(404);
    }
}
