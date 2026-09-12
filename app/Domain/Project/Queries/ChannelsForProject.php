<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;

/**
 * Private projects are never announced on the workspace channel, which would reveal them.
 */
final readonly class ChannelsForProject
{
    /**
     * @return list<string>
     */
    public function __invoke(string $projectId): array
    {
        $project = Project::query()->find($projectId);

        if ($project === null) {
            return [];
        }

        $channels = ["project.{$project->id}"];

        if ($project->visibility === ProjectVisibility::Workspace) {
            $channels[] = "workspace.{$project->workspace_id}";
        }

        return $channels;
    }
}
