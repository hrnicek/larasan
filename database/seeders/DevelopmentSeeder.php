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

class DevelopmentSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $owner = $this->user('owner@example.com', 'Alex Owner');
        $colleague = $this->user('member@example.com', 'Sam Member');
        $guest = $this->user('guest@example.com', 'Casey Guest');

        $workspace = $this->workspace($owner, 'Acme');
        $side = $this->workspace($owner, 'Side Project');

        $this->invite($workspace, $owner, $colleague, WorkspaceRole::Member);
        $this->invite($workspace, $owner, $guest, WorkspaceRole::Guest);

        $reader = $this->user('reader@example.com', 'Read Only');
        $this->join($workspace, $owner, $reader, WorkspaceRole::Member);

        $website = $this->project($workspace, $owner, 'Website', ProjectVisibility::Workspace);
        $this->project($workspace, $owner, 'Internal Tools', ProjectVisibility::Private);
        $this->project($side, $owner, 'Ideas', ProjectVisibility::Workspace);

        $this->cards($website, $owner);
        $this->readOnlyAccess($website, $reader);

        $this->command->info('Log in as owner@example.com with the password "'.self::PASSWORD.'".');
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

        $user->markEmailAsVerified();

        return $user;
    }

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
            new InviteWorkspaceMemberData(email: $invitee->email, role: $role),
        );
    }

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

    private function cards(Project $project, User $owner): void
    {
        if ($project->placements()->exists()) {
            return;
        }

        $columns = $this->columnsOf($project, $owner);

        // Only the owner is assigned: a task cannot be assigned to a pending invitee.
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
