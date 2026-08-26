<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Carbon\CarbonImmutable;

/**
 * What a custom field holds, and therefore **which column stores it**.
 *
 * The column mapping lives on the enum rather than in the Action, because it is the one fact
 * every part of this feature needs: writing a value, reading it back, sorting by it and
 * filtering on it all have to agree, and three copies of that agreement is two too many
 * (`docs/architecture/database.md`).
 *
 * **A type has one column; a column may serve several types.** `email`, `phone` and `link` are
 * text with a format rather than a new kind of storage: they sort as text, so a column of their
 * own would buy no ordering and cost an index each on the largest table in the schema. Nothing
 * reads a value without the field in hand — `TaskCustomFieldValue::value()` takes it and every
 * query joins on `custom_field_id` — so which of them a `value_text` holds is never a question
 * the row has to answer alone.
 *
 * Values are persisted, so they are stable.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
    case Email = 'email';
    case Phone = 'phone';
    case Link = 'link';

    /**
     * The column a value of this type is written to and read from.
     */
    public function column(): string
    {
        return match ($this) {
            self::Text, self::Email, self::Phone, self::Link => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
            self::Select => 'value_option_id',
        };
    }

    /**
     * Every column a value could be written to — the set the "one answer per row" rule is
     * about, and therefore **distinct**: the CHECK is built from this list, and counting
     * `value_text` once per type that shares it would make a single text answer look like four.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_values(array_unique(
            array_map(fn (self $type): string => $type->column(), self::cases()),
        ));
    }

    /**
     * What validates a value of this type. `select` is checked against the field's own options
     * rather than by shape, so it carries no rule here.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:255'],
            // Checked for shape, not for existence: whether anybody answers is not this
            // application's question.
            self::Email => ['email', 'max:255'],
            /*
             * Permissive on purpose. Numbers are written a dozen ways across countries and this
             * project has no phone-number library, so the rule keeps out prose and lets a person
             * write the number the way their colleagues will recognise it.
             */
            self::Phone => ['string', 'max:32', 'regex:/^[0-9+()\\-.\\/ ]{3,32}$/'],
            // `value_text` is 255 wide, so the length is the column's rather than a guess. A
            // longer address is refused rather than quietly cut in half.
            self::Link => ['url', 'max:255'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Boolean => ['boolean'],
            self::Select => ['uuid'],
        };
    }

    public function isSelect(): bool
    {
        return $this === self::Select;
    }

    /**
     * The value as its column wants it.
     *
     * One place, because a value arrives from a form as a string whatever it is: `"12.5"` is a
     * number, `"1"` is a boolean and `"2026-08-23"` is a date, and each column refuses the
     * others. A null is a cleared answer and stays one.
     */
    public function normalise(mixed $value): string|float|bool|CarbonImmutable|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this) {
            self::Text, self::Select, self::Email, self::Phone, self::Link => trim((string) $value),
            self::Number => (float) $value,
            self::Date => CarbonImmutable::parse((string) $value)->startOfDay(),
            // `filter_var` rather than a cast: `(bool) "false"` is true, which is the wrong
            // answer to every checkbox anybody has ever unticked.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        };
    }
}
