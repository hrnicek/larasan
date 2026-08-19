<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerCapabilityGates();
        $this->configureRateLimiting();
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
