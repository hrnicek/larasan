<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Events\WorkspaceCreated;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateWorkspace
{
    public function __construct(private Dispatcher $events) {}

    public function handle(User $owner, CreateWorkspaceData $data): Workspace
    {
        try {
            $workspace = $this->create($owner, $data);
        } catch (UniqueConstraintViolationException $exception) {
            // Only a derived slug is retried; a taken slug the caller chose must fail.
            if ($data->slug !== null) {
                throw $exception;
            }

            $workspace = $this->create($owner, $data);
        }

        $this->events->dispatch(new WorkspaceCreated($workspace->id, $owner->id));

        return $workspace;
    }

    private function create(User $owner, CreateWorkspaceData $data): Workspace
    {
        return DB::transaction(function () use ($owner, $data): Workspace {
            $workspace = Workspace::query()->create([
                'owner_id' => $owner->id,
                'name' => $data->name,
                'slug' => $data->slug ?? Workspace::slugFor($data->name),
                'timezone' => $data->timezone,
                'settings' => [],
            ]);

            // owner_id alone grants no access; workspace resolution requires a membership row.
            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
                'status' => WorkspaceMembershipStatus::Active,
                'joined_at' => now(),
            ]);

            return $workspace;
        });
    }
}
