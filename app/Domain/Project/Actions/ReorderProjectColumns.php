<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Data\ListColumns;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

final readonly class ReorderProjectColumns
{
    /**
     * @param  list<string>  $columns  field ids and built-in keys, in the order to draw them
     */
    public function handle(Project $project, User $actor, array $columns): Project
    {
        if (! $project->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw ProjectException::cannotManageProject();
        }

        $available = [
            ...$project->customFields()->pluck('custom_fields.id')->all(),
            ...ListColumns::builtIn(),
        ];

        $ordered = array_values(array_intersect(array_values(array_unique($columns)), $available));

        // Null when it matches the default, so fields attached later still append at the end.
        $project->list_columns = $ordered === $available ? null : $ordered;
        $project->save();

        return $project;
    }
}
