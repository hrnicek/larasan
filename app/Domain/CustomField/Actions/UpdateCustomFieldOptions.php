<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCustomFieldOptions
{
    /**
     * Offset that moves existing positions clear of the `(custom_field_id, position)` unique index
     * before the final order is written. The request allows fewer options than this.
     */
    private const int PARKING = 1000;

    /**
     * @param  list<array{id: ?string, label: string}>  $options  in the order they should be offered
     */
    public function handle(CustomField $field, User $actor, array $options): CustomField
    {
        if (! $field->workspace->membershipFor($actor)?->allows(Capability::CustomFieldManage)) {
            throw CustomFieldException::cannotManageFields();
        }

        if (! $field->type->isSelect()) {
            throw CustomFieldException::fieldIsNotAChoiceField();
        }

        $wanted = $this->clean($options);

        if ($wanted === []) {
            throw CustomFieldException::selectNeedsOptions();
        }

        $existing = $field->options()->get()->keyBy('id');

        foreach ($wanted as $entry) {
            if ($entry['id'] !== null && ! $existing->has($entry['id'])) {
                throw CustomFieldException::optionIsNotOnThisField();
            }
        }

        DB::transaction(function () use ($field, $wanted): void {
            $kept = array_values(array_filter(array_column($wanted, 'id')));

            $field->options()->whereNotIn('id', $kept)->delete();

            // One statement, so the unique index sees the shift as a whole rather than row by row.
            $field->options()->getQuery()->update(['position' => DB::raw('position + '.self::PARKING)]);

            // Reloaded after the shift: a model still holding its old position would not be dirty
            // when moved back to it, and would stay parked.
            $parked = $field->options()->get()->keyBy('id');

            foreach ($wanted as $position => $entry) {
                $option = $entry['id'] !== null
                    ? $parked->get($entry['id'])
                    : new CustomFieldOption;

                if (! $option instanceof CustomFieldOption) {
                    continue;
                }

                $option->label = $entry['label'];
                $option->position = $position + 1;
                $option->custom_field_id = $field->id;
                $option->save();
            }

            // Answers to a deleted option were nulled by the foreign key; remove the now-empty rows.
            $field->values()->whereNull('value_option_id')->delete();
        });

        return $field->load('options');
    }

    /**
     * @param  list<array{id: ?string, label: string}>  $options
     * @return list<array{id: ?string, label: string}>
     */
    private function clean(array $options): array
    {
        $cleaned = [];

        foreach ($options as $entry) {
            $label = trim($entry['label']);

            if ($label === '') {
                continue;
            }

            $cleaned[] = ['id' => $entry['id'], 'label' => $label];
        }

        return $cleaned;
    }
}
