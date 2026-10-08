<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Workspace\Data\UpdateWorkspaceData;
use App\Domain\Workspace\Events\WorkspaceUpdated;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateWorkspace
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, UpdateWorkspaceData $data): Workspace
    {
        $workspace->fill(array_filter([
            'name' => $data->name,
            'slug' => $data->slug,
            'timezone' => $data->timezone,
        ], fn (?string $value): bool => $value !== null));

        // The slug is never re-derived on rename, so saved links keep working.
        $changed = array_keys($workspace->getDirty());

        if ($changed === []) {
            return $workspace;
        }

        $workspace->save();

        $this->events->dispatch(new WorkspaceUpdated($workspace->id, $changed));

        return $workspace;
    }
}
