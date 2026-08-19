<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
