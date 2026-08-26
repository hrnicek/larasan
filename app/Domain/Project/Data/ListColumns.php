<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * The order a project's list draws its columns in, reconciled with what the project actually has.
 *
 * A stored order is a wish, not a fact: a field named in it may since have been detached, and one
 * attached afterwards is named nowhere. So the stored order is **filtered and then completed** —
 * keys that no longer mean anything are dropped, and anything the project has that the order does
 * not mention is appended in the default order.
 *
 * That is what makes the column safe to leave alone: a project nobody has reordered stores null
 * and draws exactly what it drew before this existed.
 *
 * The task name is not in here. It is always the first column, so storing it would be storing a
 * fact that cannot vary, and a stored order that could omit it would be an order that draws a list
 * with no titles in it.
 */
final readonly class ListColumns
{
    public const string ASSIGNEE = 'assignee';

    public const string DUE = 'due';

    public const string PRIORITY = 'priority';

    /**
     * The columns every list has, in the order they came in before any of this existed.
     *
     * @return list<string>
     */
    public static function builtIn(): array
    {
        return [self::ASSIGNEE, self::DUE, self::PRIORITY];
    }

    /**
     * The project's columns, described for the screens that draw and reorder them.
     *
     * One builder, because three places need the same answer — the list's header, its rows, and
     * the drawer that reorders them — and a header that has drifted from the cell beneath it
     * labels the wrong thing with confidence.
     *
     * `type` is the field's, and it is what lets a row draw a tick for a boolean rather than the
     * word `true`; a built-in column has none.
     *
     * @return list<array{key: string, kind: string, label: string, type: string|null}>
     */
    public static function describe(Project $project): array
    {
        $labels = [
            self::ASSIGNEE => (string) __('Assignee'),
            self::DUE => (string) __('Due'),
            self::PRIORITY => (string) __('Priority'),
        ];

        $fields = $project->customFields->keyBy('id');
        $columns = [];

        foreach (self::for($project, $project->customFields) as $key) {
            if (isset($labels[$key])) {
                $columns[] = ['key' => $key, 'kind' => $key, 'label' => $labels[$key], 'type' => null];

                continue;
            }

            $field = $fields->get($key);

            if ($field instanceof CustomField) {
                $columns[] = [
                    'key' => $key,
                    'kind' => 'field',
                    'label' => $field->name,
                    'type' => $field->type->value,
                ];
            }
        }

        return $columns;
    }

    /**
     * The project's columns, in the order it draws them.
     *
     * @param  Collection<int, CustomField>  $fields  the project's fields, in pivot order
     * @return list<string>
     */
    public static function for(Project $project, Collection $fields): array
    {
        // Fields first, then the built-in three: the order a project has drawn since Phase 150.
        $available = [...$fields->modelKeys(), ...self::builtIn()];

        $stored = is_array($project->list_columns)
            ? array_values(array_filter($project->list_columns, is_string(...)))
            : [];

        $ordered = array_values(array_intersect($stored, $available));

        return [...$ordered, ...array_values(array_diff($available, $ordered))];
    }
}
