<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /** An operator allowlist, not a workspace capability: job payloads span every workspace. See ADR-0011. */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            // Unverified addresses never match: any account can claim an unregistered operator address.
            if (! $user->hasVerifiedEmail()) {
                return false;
            }

            /** @var list<string> $operators */
            $operators = config('horizon.operators', []);

            return in_array(Str::lower($user->email), array_map(Str::lower(...), $operators), strict: true);
        });
    }
}
