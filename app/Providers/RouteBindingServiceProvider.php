<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Comment\Models\Comment;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\File\Models\Attachment;
use App\Domain\Page\Models\Page;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings live in a provider because `route:cache` never loads the route files.
 * Each one scopes its model to the actor's current workspace, so another tenant's row is a 404.
 */
class RouteBindingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Archived projects still resolve so they can be restored.
        Route::bind('project', function (string $id): Project {
            [$user, $workspace] = $this->actor();

            return app(VisibleProjectsForUser::class)
                ->query($workspace, $user, includeArchived: true)
                ->whereKey($id)
                ->first() ?? abort(404);
        });

        // Scoped to the workspace only; access within it is the policy's 403.
        Route::bind('task', function (string $id): Task {
            [, $workspace] = $this->actor();

            return Task::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        Route::bind('section', function (string $id): Section {
            return Section::query()->whereKey($id)->whereIn('project_id', $this->visibleProjectIds())->first() ?? abort(404);
        });

        Route::bind('page', function (string $id): Page {
            return Page::query()->whereKey($id)->whereIn('project_id', $this->visibleProjectIds())->first() ?? abort(404);
        });

        Route::bind('placement', function (string $id): TaskProjectMembership {
            return TaskProjectMembership::query()
                ->whereKey($id)
                ->whereIn('project_id', $this->visibleProjectIds())
                ->first() ?? abort(404);
        });

        Route::bind('comment', function (string $id): Comment {
            [, $workspace] = $this->actor();

            return Comment::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        // The soft-delete scope on File means an attachment to a removed file does not resolve.
        Route::bind('attachment', function (string $id): Attachment {
            [, $workspace] = $this->actor();

            return Attachment::query()
                ->whereKey($id)
                ->whereHas('file', fn ($files) => $files->where('workspace_id', $workspace->id))
                ->first() ?? abort(404);
        });

        Route::bind('tag', function (string $id): Tag {
            [, $workspace] = $this->actor();

            return Tag::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        Route::bind('field', function (string $id): CustomField {
            [, $workspace] = $this->actor();

            return CustomField::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        // The inbox actions do no authorization of their own and rely on this scoping.
        Route::bind('notification', function (string $id): DatabaseNotification {
            [$user, $workspace] = $this->actor();

            return DatabaseNotification::query()
                ->whereKey($id)
                ->where('workspace_id', $workspace->id)
                ->where('notifiable_type', 'user')
                ->where('notifiable_id', $user->id)
                ->first() ?? abort(404);
        });
    }

    /**
     * @return array{User, Workspace}
     */
    private function actor(): array
    {
        $user = request()->user();

        if (! $user instanceof User) {
            abort(404);
        }

        return [$user, app(CurrentWorkspace::class)->for($user) ?? abort(404)];
    }

    /**
     * @return Builder<Project>
     */
    private function visibleProjectIds(): Builder
    {
        [$user, $workspace] = $this->actor();

        return app(VisibleProjectsForUser::class)
            ->query($workspace, $user, includeArchived: true)
            ->select('projects.id');
    }
}
