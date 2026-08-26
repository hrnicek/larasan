<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Actions;

use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Change what a choice field offers.
 *
 * The one operation Phase 150 left out: `DefineCustomField` writes the choices once, at the moment
 * the field is invented, and until now nothing could add a stage, fix a typo or retire a value
 * nobody picks any more.
 *
 * The list arrives **whole and is reconciled**, rather than as three endpoints for adding,
 * renaming and removing. An option list is short and is read as one thing — the migration says so
 * by giving it a plain `position` with a unique constraint rather than the sparse ordering tasks
 * use — and a screen that edits it as one thing cannot send a half-applied order.
 *
 * An entry with an id is kept and relabelled, one without is created, and an id that was there and
 * is not sent is deleted. Deleting a choice empties the answers that pointed at it
 * (`nullOnDelete`), and this removes the rows that are left: "no answer" and "an answer that is
 * blank" are the same thing to a reader, which is the rule `SetTaskCustomFieldValue` already keeps.
 */
final readonly class UpdateCustomFieldOptions
{
    /**
     * How far existing rows are moved out of the way before the final order is written. The unique
     * index on `(custom_field_id, position)` is checked per statement, so one bulk shift is enough
     * — and the request allows fewer choices than this, so the two ranges cannot meet.
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

            /*
             * Read back *after* the shift. Eloquent compares a new value against the one the model
             * was loaded with, so a row that is going back to the position it already held would
             * not be dirty and would silently stay parked.
             */
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

            /*
             * An answer that pointed at a deleted choice is now a row with nothing in any column.
             * The reader sees no answer either way; a query sees two different things, so the row
             * goes.
             */
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
