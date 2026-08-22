<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Queries;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * The workspace a request is operating in, resolved once.
 *
 * `ResolveWorkspaceForUser` is a query and stays one. The problem it cannot solve on its own
 * is that four route bindings and one middleware all need the answer, and route model
 * binding runs before the middleware that would have cached it — so a request that touched
 * a project ran the resolution twice and its membership subquery four times.
 *
 * Request-scoped, and emptied whenever a membership row changes, for the same reason
 * `MembershipRegistry` is: an answer about access must never outlive the row it came from.
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
