<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;

/**
 * Which channels may hear that a project changed.
 *
 * A private project is announced on its own channel and nowhere else: its id on the
 * workspace channel would tell every member that it exists, which is what the private
 * project was for. A workspace-visible project is also announced to the workspace, because
 * its name is in a sidebar every member is looking at.
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
