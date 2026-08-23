<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notification\Channels\WorkspaceDatabaseChannel;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Queries\CurrentWorkspace;
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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: the memo must not survive the request that filled it.
        $this->app->scoped(MembershipRegistry::class);
        $this->app->scoped(CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
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
    }

    /**
     * A refusal or a wrong address is answered by this application rather than by the framework.
     *
     * `local` is left alone deliberately: Inertia's development modal says far more about what
     * went wrong than a page ever should, and the person reading it is the one who broke it.
     * `testing` is **not** excluded, because a page nobody can test is a page nobody knows works.
     *
     * A request that asked for JSON keeps getting JSON — `shouldRenderJsonWhen` in
     * `bootstrap/app.php` decides that, and an error page would be a surprising answer to an
     * `Accept: application/json` (TASK-180-009).
     */
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

    /**
     * Short names for the models a polymorphic column can point at.
     *
     * Enforced rather than merely registered: a class name written into `commentable_type` is
     * a rename waiting to break a table, and `enforceMorphMap()` turns an unmapped model into
     * an error at the moment somebody writes one instead of a silent row nobody can read back.
     */
    protected function enforceMorphAliases(): void
    {
        Relation::enforceMorphMap([
            'task' => Task::class,
            // A notification is addressed to an account, and `notifiable_type` is a morph
            // column like any other.
            'user' => User::class,
        ]);
    }

    /**
     * Every database notification carries the workspace it came from.
     *
     * The channel is replaced rather than the row written by hand, so everything else the
     * framework does — the id, the morph, `read_at`, the `Notifiable` relation — keeps working
     * and only the extra column is this application's business.
     */
    protected function writeNotificationsWithTheirWorkspace(): void
    {
        Notification::resolved(function (ChannelManager $channels): void {
            $channels->extend('database', fn (Application $app): WorkspaceDatabaseChannel => $app->make(WorkspaceDatabaseChannel::class));
        });
    }

    /**
     * An authorization answer must never outlive the row it came from.
     * `MembershipRegistry` memoises the two membership lookups for the length of one
     * request; these events are what stop it from answering with a role that has since
     * changed — including inside an Action that reads a membership again after writing it.
     */
    protected function forgetMembershipsWhenTheyChange(): void
    {
        $flush = function (): void {
            $this->app->make(MembershipRegistry::class)->flush();
            $this->app->make(CurrentWorkspace::class)->flush();
        };

        foreach ([WorkspaceMembership::class, ProjectMembership::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }

    /**
     * A notification is addressed to one person, so it means nothing without them.
     *
     * `notifiable_id` is a morph column and cannot carry a foreign key, so the cleanup is the
     * domain's. Comments and activities deliberately do the opposite and outlive their author:
     * other people took part in those, and deleting somebody must not rewrite what happened.
     */
    protected function removeNotificationsWithTheAccount(): void
    {
        User::deleted(function (User $user): void {
            DB::table('notifications')
                ->where('notifiable_type', 'user')
                ->where('notifiable_id', $user->id)
                ->delete();
        });
    }

    /**
     * Named rather than `throttle:10,1`: an unnamed limiter shares one bucket across every
     * route that uses the same numbers, so creating workspaces would eat the invitation
     * budget. Keyed by user — both routes are behind `auth`.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('workspace-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('workspace-creation', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('project-creation', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));

        /*
         * Moving a card or a column locks the whole column for the length of its transaction
         * (ADR-0009), and the board sends one request per drop — the first endpoints in this
         * application that are both frequent and expensive (TASK-070-016).
         *
         * Sixty a minute is one drag a second, sustained, which is faster than a person
         * dragging as fast as they can and far below what a stuck client would produce. It is
         * a rate, not a round number: the point is to bound a loop, not to ration a user.
         */
        RateLimiter::for('task-moves', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->id));

        /*
         * Writing a comment is cheap for the server and expensive for everybody else: each one
         * notifies every follower and the assignee (TASK-110-011), so a loop here fills other
         * people's inboxes rather than a table. Thirty a minute is faster than anybody types
         * and far below what a stuck client produces.
         */
        RateLimiter::for('comments', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));

        // An upload writes bytes and is the most expensive thing a member can ask for without
        // anybody approving it. Twenty a minute is faster than anybody picks files.
        RateLimiter::for('attachments', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));
    }

    /**
     * One Gate ability per capability, so any policy, controller, console command or
     * queued job asks the same question — `$user->can(Capability::TaskCreate, $workspace)`
     * — and the answer always comes from the membership row rather than from a role
     * string somebody compared by hand (ADR-0010).
     */
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

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        /*
         * Deliberately unconditional, unlike the two guards above. A missed with() or a
         * missing column should degrade in production rather than return a 500, but an
         * attribute silently dropped by fill() loses user data with no trace, and this
         * project ranks data integrity above the framework default.
         */
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
