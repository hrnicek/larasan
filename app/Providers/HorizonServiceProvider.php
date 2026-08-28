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

            /*
             * Verified, then listed. The list cannot be changed by anyone who compromises
             * an account, but the address matched against it can: registration does not
             * prove mailbox control, and `PATCH settings/profile` lets any account change
             * its address. Without this check, claiming an unregistered ops alias hands
             * over every tenant's job payloads.
             */
            if (! $user->hasVerifiedEmail()) {
                return false;
            }

            /** @var list<string> $operators */
            $operators = config('horizon.operators', []);

            return in_array(Str::lower($user->email), array_map(Str::lower(...), $operators), strict: true);
        });
    }
}
