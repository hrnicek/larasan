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

        /*
         * A slug the caller did not supply stays as it is. Re-deriving it from a renamed
         * workspace would break every link anyone had saved, which is a decision for the
         * person renaming it rather than a side effect of renaming.
         */
        $changed = array_keys($workspace->getDirty());

        if ($changed === []) {
            return $workspace;
        }

        $workspace->save();

        $this->events->dispatch(new WorkspaceUpdated($workspace->id, $changed));

        return $workspace;
    }
}
