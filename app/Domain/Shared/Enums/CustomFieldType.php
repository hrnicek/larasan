<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

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
}
