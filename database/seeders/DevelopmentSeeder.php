<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\CreateWorkspace;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * An account to log in with, and enough around it to see the application working.
 *
 * Everything is created through the domain Actions rather than through factories, so what
 * a developer opens is what the application actually produces — a workspace with an owner
 * membership, a project whose creator is its owner, a pending invitation in the state the
 * invite flow leaves behind. Factories can build shapes the domain never reaches, and
 * seeded data that does is a bug report waiting to be filed against the wrong thing.
 *
 * Idempotent: running it twice changes nothing.
 */
class DevelopmentSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $owner = $this->user('hrncir@example.com', 'Jakub Hrnčíř');
        $colleague = $this->user('kolega@example.com', 'Kolega');
        $guest = $this->user('klient@example.com', 'Klient');

        $workspace = $this->workspace($owner, 'Zondy');
        $side = $this->workspace($owner, 'Side Project');

        $this->invite($workspace, $owner, $colleague, WorkspaceRole::Member);
        $this->invite($workspace, $owner, $guest, WorkspaceRole::Guest);

        $this->project($workspace, $owner, 'Website', ProjectVisibility::Workspace);
        $this->project($workspace, $owner, 'Internal Tools', ProjectVisibility::Private);
        $this->project($side, $owner, 'Ideas', ProjectVisibility::Workspace);

        $this->command->info('Log in as hrncir@example.com with the password "'.self::PASSWORD.'".');
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = bcrypt(self::PASSWORD);
        $user->save();

        // Verification is enforced (TASK-020-024): an unverified seeded account would meet
        // the notice screen instead of the application.
        $user->markEmailAsVerified();

        return $user;
    }

    /**
     * Looked up **through the owner's memberships**, not by slug alone: a development
     * database may already hold someone else's workspace at that slug, and returning it
     * would make the seeder try to work inside a workspace this user has no membership in.
     */
    private function workspace(User $owner, string $name): Workspace
    {
        $existing = $owner->workspaces()->where('workspaces.name', $name)->first();

        if ($existing instanceof Workspace) {
            return $existing;
        }

        return app(CreateWorkspace::class)->handle($owner, new CreateWorkspaceData(name: $name));
    }

    private function invite(Workspace $workspace, User $inviter, User $invitee, WorkspaceRole $role): void
    {
        if ($workspace->membershipFor($invitee) !== null) {
            return;
        }

        app(InviteWorkspaceMember::class)->handle(
            $workspace,
            $inviter,
            new InviteWorkspaceMemberData(userId: $invitee->id, role: $role),
        );
    }

    private function project(Workspace $workspace, User $creator, string $name, ProjectVisibility $visibility): void
    {
        if ($workspace->projects()->where('name', $name)->exists()) {
            return;
        }

        app(CreateProject::class)->handle($workspace, $creator, new CreateProjectData(
            name: $name,
            visibility: $visibility,
        ));
    }
}
