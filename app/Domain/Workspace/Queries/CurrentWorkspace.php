<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Queries;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * Request-scoped memo, flushed with MembershipRegistry whenever a membership row changes.
 */
final class CurrentWorkspace
{
    /** @var array<string, Workspace|null> */
    private array $resolved = [];

    public function __construct(private readonly ResolveWorkspaceForUser $resolve) {}

    public function for(User $user, ?string $slug = null): ?Workspace
    {
        $key = $user->id.':'.($slug ?? '');

        if (! array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = ($this->resolve)($user, $slug);
        }

        return $this->resolved[$key];
    }

    public function flush(): void
    {
        $this->resolved = [];
    }
}
