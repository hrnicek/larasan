<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Carbon\CarbonImmutable;

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
     * Distinct, because the one-answer-per-row CHECK constraint is built from this list.
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
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:255'],
            self::Email => ['email', 'max:255'],
            // Permissive by design: rejects prose but accepts any national number format.
            self::Phone => ['string', 'max:32', 'regex:/^[0-9+()\\-.\\/ ]{3,32}$/'],
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

    public function normalise(mixed $value): string|float|bool|CarbonImmutable|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this) {
            self::Text, self::Select, self::Email, self::Phone, self::Link => trim((string) $value),
            self::Number => (float) $value,
            self::Date => CarbonImmutable::parse((string) $value)->startOfDay(),
            // A (bool) cast would turn the string "false" into true.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        };
    }
}
