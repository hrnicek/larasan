<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Comment\Models\Comment;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\File\Models\Attachment;
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
 * Every explicit route binding, in a provider rather than in the route files.
 *
 * These bindings are load-bearing security: each one resolves its model inside the workspace the
 * actor is currently in, so a row from another tenant is a 404 rather than a 403 — a code that
 * would confirm the id exists. `routes/inbox.php` went further and said its Actions need no
 * authorization of their own, "because there is no way to name a notification that is not
 * yours".
 *
 * That was true only while the route files were loaded. `php artisan route:cache` — which every
 * production deployment runs — loads the cached route table and **never reads the route files**,
 * so bindings declared there silently stop existing and Laravel falls back to implicit binding
 * by primary key. Measured rather than assumed (TASK-180-001): with routes cached, 79 tests fail,
 * two of them because one account can mark another account's notification read and reach another
 * workspace's custom field.
 *
 * A provider is registered whether routes are cached or not, which is why they live here.
 */
class RouteBindingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * The {project} parameter resolves inside the workspace the actor is currently in, and
         * only among the projects they may see. A project from another workspace, or a private
         * one they were never given, is indistinguishable from one that does not exist — 403
         * would confirm the id, which is what makes a leaked UUID worth something.
         *
         * Resolved here rather than by implicit binding plus a check afterwards: the binding runs
         * before this application's middleware in the web group, so a check placed after it would
         * depend on an ordering that is easy to break and silent when broken. Archived projects
         * still resolve — their settings screen is where they are restored.
         */
        Route::bind('project', function (string $id): Project {
            [$user, $workspace] = $this->actor();

            return app(VisibleProjectsForUser::class)
                ->query($workspace, $user, includeArchived: true)
                ->whereKey($id)
                ->first() ?? abort(404);
        });

        /*
         * `{task}` resolves inside the workspace the actor is currently in, the way projects and
         * sections already bind: a task from another workspace is indistinguishable from one that
         * does not exist.
         *
         * Within the workspace the policy answers, not the binding. A guest is a member of this
         * workspace and may not read its tasks, and 403 is the honest reply to somebody who is
         * standing in the right building — the 404 rule is about tenants, not roles.
         */
        Route::bind('task', function (string $id): Task {
            [, $workspace] = $this->actor();

            return Task::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        /*
         * `{section}` resolves through the projects the actor may see in the workspace they are
         * currently in — the same rule `{project}` binds with, one level down. A section of a
         * project they cannot open is indistinguishable from one that does not exist.
         */
        Route::bind('section', function (string $id): Section {
            return Section::query()->whereKey($id)->whereIn('project_id', $this->visibleProjectIds())->first() ?? abort(404);
        });

        /*
         * `{placement}` resolves through the same projects, exactly as `{section}` does — a card
         * in a project they cannot open is indistinguishable from one that does not exist. Within
         * a project the policy answers.
         */
        Route::bind('placement', function (string $id): TaskProjectMembership {
            return TaskProjectMembership::query()
                ->whereKey($id)
                ->whereIn('project_id', $this->visibleProjectIds())
                ->first() ?? abort(404);
        });

        /*
         * `{comment}` resolves inside the workspace the actor is currently in, as every other
         * bound model here does: a comment from another tenant is indistinguishable from one that
         * does not exist. Soft-deleted comments are not bound at all — a removed comment can be
         * read in a thread, but there is nothing left to address.
         *
         * Whether the actor may read the subject, edit their own words or remove somebody else's
         * is the policy's answer, not the binding's.
         */
        Route::bind('comment', function (string $id): Comment {
            [, $workspace] = $this->actor();

            return Comment::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        /*
         * `{attachment}` resolves through its file's workspace, which is the workspace the actor
         * is currently in. A soft-deleted file does not resolve at all — it has stopped being
         * reachable, which is the whole point of removing it that way (TASK-120-007).
         *
         * Whether the actor may open the thing it hangs from is the policy's answer, not the
         * binding's, and it is asked in the controller: a storage path is never a capability
         * (ADR-0007).
         */
        Route::bind('attachment', function (string $id): Attachment {
            [, $workspace] = $this->actor();

            return Attachment::query()
                ->whereKey($id)
                ->whereHas('file', fn ($files) => $files->where('workspace_id', $workspace->id))
                ->first() ?? abort(404);
        });

        /*
         * `{tag}` resolves inside the workspace the actor is currently in: a tag from another
         * tenant is indistinguishable from one that does not exist.
         */
        Route::bind('tag', function (string $id): Tag {
            [, $workspace] = $this->actor();

            return Tag::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        /*
         * `{field}` resolves inside the workspace the actor is currently in. Whether it is shown
         * on this task is the Action's answer, because that is a question about the task rather
         * than about the field.
         */
        Route::bind('field', function (string $id): CustomField {
            [, $workspace] = $this->actor();

            return CustomField::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
        });

        /*
         * `{notification}` resolves only inside the reader's own inbox, in the workspace they are
         * currently in. Somebody else's notification is a 404 rather than a 403: it is addressed
         * to one person, so its existence is not something anybody else is entitled to confirm.
         *
         * That also means the Actions behind these routes need no authorization of their own —
         * there is no way to name a notification that is not yours, **as long as this binding
         * runs**, which is why it is registered in a provider rather than in a route file.
         */
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
     * The signed-in account and the workspace it is currently in, or a 404.
     *
     * Every binding starts here, and none of them may resolve anything without both: an
     * unauthenticated request and a request with no current workspace are the two states in
     * which "which workspace is this row in" has no answer.
     *
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
     * The ids of the projects this actor may open, as a subquery rather than a list.
     *
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
