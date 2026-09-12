<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notification\Channels\WorkspaceDatabaseChannel;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Policies\TaskPolicy;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Responses\ModalResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Inertia\ResponseFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the memo must not survive the request that filled it.
        $this->app->scoped(MembershipRegistry::class);
        $this->app->scoped(CurrentWorkspace::class);
        // The Gate resolves policies per check, so a memoising policy must be scoped to be effective.
        $this->app->scoped(TaskPolicy::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->enforceMorphAliases();
        $this->writeNotificationsWithTheirWorkspace();
        $this->forgetMembershipsWhenTheyChange();
        $this->removeNotificationsWithTheAccount();
        $this->registerCapabilityGates();
        $this->configureRateLimiting();
        $this->renderErrorsAsThisApplication();
        $this->answerModalsWithTheirOwnUrl();
    }

    /** Replaces the `modal` macro registered by the package's own service provider. */
    private function answerModalsWithTheirOwnUrl(): void
    {
        ResponseFactory::macro(
            'modal',
            fn (string $component, array $props = []): ModalResponse => new ModalResponse($component, $props),
        );
    }

    private function renderErrorsAsThisApplication(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if ($this->app->environment('local') || $response->request->expectsJson()) {
                return null;
            }

            if (! in_array($response->statusCode(), [403, 404, 500, 503], true)) {
                return null;
            }

            return $response->render('Error', ['status' => $response->statusCode()])->withSharedData();
        });
    }

    protected function enforceMorphAliases(): void
    {
        Relation::enforceMorphMap([
            'task' => Task::class,
            'project' => Project::class,
            'user' => User::class,
        ]);
    }

    protected function writeNotificationsWithTheirWorkspace(): void
    {
        Notification::resolved(function (ChannelManager $channels): void {
            $channels->extend('database', fn (Application $app): WorkspaceDatabaseChannel => $app->make(WorkspaceDatabaseChannel::class));
        });
    }

    protected function forgetMembershipsWhenTheyChange(): void
    {
        $flushMemberships = function (): void {
            $this->app->make(MembershipRegistry::class)->flush();
            $this->app->make(CurrentWorkspace::class)->flush();
        };

        foreach ([WorkspaceMembership::class, ProjectMembership::class] as $model) {
            $model::saved($flushMemberships);
            $model::deleted($flushMemberships);
        }

        // TaskPolicy also depends on placements and on each project's visibility, access and archive state.
        $flushTaskAccess = function (): void {
            $this->app->make(TaskPolicy::class)->flush();
        };

        foreach ([WorkspaceMembership::class, ProjectMembership::class, TaskProjectMembership::class, Project::class] as $model) {
            $model::saved($flushTaskAccess);
            $model::deleted($flushTaskAccess);
        }
    }

    /** `notifiable_id` is a morph column and cannot carry a foreign key, so the cleanup happens here. */
    protected function removeNotificationsWithTheAccount(): void
    {
        User::deleted(function (User $user): void {
            DB::table('notifications')
                ->where('notifiable_type', 'user')
                ->where('notifiable_id', $user->id)
                ->delete();
        });
    }

    /** Named limiters, because inline `throttle:x,y` middleware shares one per-user bucket across routes. */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('workspace-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('workspace-creation', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('project-creation', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));

        // Each move locks its column for the transaction; the limit bounds a runaway client. See ADR-0009.
        RateLimiter::for('task-moves', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->id));

        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->user()?->id));

        RateLimiter::for('page-creation', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('page-saves', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->user()?->id));

        RateLimiter::for('comments', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));

        RateLimiter::for('attachments', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));

        RateLimiter::for('avatar-uploads', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));
        RateLimiter::for('password-updates', fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->user()?->id));
    }

    protected function registerCapabilityGates(): void
    {
        foreach (Capability::cases() as $capability) {
            Gate::define(
                $capability->value,
                fn (User $user, Workspace $workspace): bool => $workspace
                    ->membershipFor($user)?->allows($capability) ?? false,
            );
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        // Unconditional, unlike the guards above: a silently discarded attribute loses data without a trace.
        Model::preventSilentlyDiscardingAttributes();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
