<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Failed job payloads are serialised domain objects spanning every workspace, so
     * this gate is an environment allow-list rather than a "not production" check —
     * self-registration is enabled, and any staging or demo deployment would otherwise
     * hand that data to anyone who signs up. Replaced by the workspace.manage
     * capability in TASK-030-013.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            return app()->environment(['local', 'testing']);
        });
    }
}
