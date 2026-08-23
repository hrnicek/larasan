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
 * Values are persisted, so they are stable.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';

    /**
     * The column a value of this type is written to and read from.
     */
    public function column(): string
    {
        return match ($this) {
            self::Text => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
            self::Select => 'value_option_id',
        };
    }

    /**
     * Every column a value could be written to — the set the "one answer per row" rule is
     * about.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(fn (self $type): string => $type->column(), self::cases());
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
            self::Text, self::Select => trim((string) $value),
            self::Number => (float) $value,
            self::Date => CarbonImmutable::parse((string) $value)->startOfDay(),
            // `filter_var` rather than a cast: `(bool) "false"` is true, which is the wrong
            // answer to every checkbox anybody has ever unticked.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        };
    }
}
