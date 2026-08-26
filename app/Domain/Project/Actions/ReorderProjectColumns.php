<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Data\ListColumns;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Decide the order this project's list draws its columns in.
 *
 * `custom_field.manage`, the same permission attaching a field asks for, and for the same reason
 * `AttachFieldToProject` states: what everybody's board looks like is not what `task.update` is
 * for. It is one drawer, one kind of decision, one permission.
 *
 * The whole order arrives at once. An order applied in halves is an order somebody can interrupt
 * into a shape nobody chose, and the list is short enough that sending it whole costs nothing.
 * What is stored is filtered to keys the project could actually draw — a payload naming a field
 * from another project is not an error worth a refusal, it is a key that means nothing, and
 * `ListColumns::for()` would drop it on the way out anyway.
 */
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

        // Storing nothing rather than a copy of the default: a project that is put back the way it
        // was should read as one nobody has reordered, so a field attached later still lands at
        // the end instead of behind an order that happens to name everything.
        $project->list_columns = $ordered === $available ? null : $ordered;
        $project->save();

        return $project;
    }
}
