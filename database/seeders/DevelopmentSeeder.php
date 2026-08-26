<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Actions\CreateSection;
use App\Domain\Section\Actions\RenameSection;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Data\UpdateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Workspace\Actions\AnswerWorkspaceInvitation;
use App\Domain\Workspace\Actions\CreateWorkspace;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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

        /*
         * One account that has actually accepted, so the application can be looked at as
         * somebody other than the owner. The colleague's invitation stays pending on
         * purpose — it is one of the states this seeder exists to show — and a pending
         * member cannot be given work or read a board.
         */
        $reader = $this->user('reader@example.com', 'Read Only');
        $this->join($workspace, $owner, $reader, WorkspaceRole::Member);

        $website = $this->project($workspace, $owner, 'Website', ProjectVisibility::Workspace);
        $this->project($workspace, $owner, 'Internal Tools', ProjectVisibility::Private);
        $this->project($side, $owner, 'Ideas', ProjectVisibility::Workspace);

        $this->cards($website, $owner);
        $this->readOnlyAccess($website, $reader);

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

    /**
     * An invitation that is immediately accepted: a member who is actually in the workspace,
     * rather than one who has been asked.
     */
    private function join(Workspace $workspace, User $inviter, User $invitee, WorkspaceRole $role): void
    {
        $existing = $workspace->membershipFor($invitee);

        if ($existing?->status === WorkspaceMembershipStatus::Active) {
            return;
        }

        $this->invite($workspace, $inviter, $invitee, $role);

        $membership = $workspace->membershipFor($invitee);

        if ($membership instanceof WorkspaceMembership) {
            app(AnswerWorkspaceInvitation::class)->accept($membership, $invitee);
        }
    }

    /**
     * Somebody who can open the board and change nothing on it, so the read-only rendering
     * can be looked at rather than only asserted.
     */
    private function readOnlyAccess(Project $project, User $reader): void
    {
        if ($project->memberFor($reader) !== null) {
            return;
        }

        ProjectMembership::query()->create([
            'project_id' => $project->id,
            'user_id' => $reader->id,
            'access_level' => ProjectAccessLevel::Viewer,
        ]);
    }

    private function project(Workspace $workspace, User $creator, string $name, ProjectVisibility $visibility): Project
    {
        $existing = $workspace->projects()->where('name', $name)->first();

        if ($existing instanceof Project) {
            return $existing;
        }

        return app(CreateProject::class)->handle($workspace, $creator, new CreateProjectData(
            name: $name,
            visibility: $visibility,
        ));
    }

    /**
     * The project's columns, as a board worth looking at.
     *
     * `CreateProject` opens a project with one placeholder column, which is where a team
     * starts rather than where it ends up. A seeded project exists to be looked at, so it
     * gets the three a team would have written itself — named here rather than read from
     * `Section::DEFAULT_NAMES`, which answers a different question. The placeholder becomes
     * the first of them instead of sitting empty beside them.
     *
     * @return Collection<int, Section>
     */
    private function columnsOf(Project $project, User $owner): Collection
    {
        $columns = $project->sections()->get();

        if ($columns->count() > 1) {
            return $columns;
        }

        $names = ['Backlog', 'In progress', 'Done'];
        $placeholder = $columns->first();

        if ($placeholder instanceof Section) {
            app(RenameSection::class)->handle($placeholder, $owner, new UpdateSectionData(
                name: (string) array_shift($names),
                color: $placeholder->color,
            ));
        }

        foreach ($names as $name) {
            app(CreateSection::class)->handle($project, $owner, new CreateSectionData(name: $name));
        }

        return $project->sections()->get();
    }

    /**
     * A board with something on it, so the list view can be looked at rather than only
     * tested. Skipped once the project holds anything, which keeps re-running the seeder
     * from stacking duplicate cards.
     */
    private function cards(Project $project, User $owner): void
    {
        if ($project->placements()->exists()) {
            return;
        }

        $columns = $this->columnsOf($project, $owner);

        /*
         * Only the owner is assigned anything. The colleague's invitation is still pending
         * on purpose — it is one of the states this seeder exists to show — and a task
         * cannot be assigned to somebody who has not accepted yet (TASK-060-012).
         */
        $plan = [
            ['Draft the new home page', 0, TaskPriority::High, $owner],
            ['Rewrite the pricing copy', 0, TaskPriority::Medium, null],
            ['Cut the hero image weight', 1, TaskPriority::High, $owner],
            ['Fix the mobile navigation', 1, TaskPriority::Urgent, null],
            ['Ship the release notes', 2, TaskPriority::Low, $owner],
            ['Decide what to do about the blog', null, TaskPriority::Low, null],
        ];

        foreach ($plan as [$title, $column, $priority, $assignee]) {
            $task = app(CreateTask::class)->handle($project->workspace, $owner, new CreateTaskData(
                title: $title,
                priority: $priority,
                assigneeId: $assignee?->id,
            ));

            $placement = app(AttachTaskToProject::class)->handle($task, $project, $owner);

            if ($column !== null) {
                app(MoveTaskInProject::class)->handle($placement, $owner, $columns->get($column));
            }
        }
    }
}
