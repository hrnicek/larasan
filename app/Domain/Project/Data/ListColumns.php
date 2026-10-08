<?php

declare(strict_types=1);

namespace App\Domain\Project\Data;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * The task name is always the first column and is never part of the stored order.
 */
final readonly class ListColumns
{
    public const string ASSIGNEE = 'assignee';

    public const string DUE = 'due';

    public const string PRIORITY = 'priority';

    /**
     * @return list<string>
     */
    public static function builtIn(): array
    {
        return [self::ASSIGNEE, self::DUE, self::PRIORITY];
    }

    /**
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
     * @param  Collection<int, CustomField>  $fields  the project's fields, in pivot order
     * @return list<string>
     */
    public static function for(Project $project, Collection $fields): array
    {
        $available = [...$fields->modelKeys(), ...self::builtIn()];

        $stored = is_array($project->list_columns)
            ? array_values(array_filter($project->list_columns, is_string(...)))
            : [];

        $ordered = array_values(array_intersect($stored, $available));

        return [...$ordered, ...array_values(array_diff($available, $ordered))];
    }
}
