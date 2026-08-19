<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
     * Failed job payloads are serialised domain objects spanning every workspace, so this
     * is an operator check rather than a workspace capability: `workspace.manage` is held
     * by the owner of any workspace, and anyone can create one (ADR-0011).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            /** @var list<string> $operators */
            $operators = config('horizon.operators', []);

            return in_array(Str::lower($user->email), array_map(Str::lower(...), $operators), strict: true);
        });
    }
}
