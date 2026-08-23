<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Carbon\CarbonImmutable;

it('gives every type its own column', function (): void {
    $columns = CustomFieldType::columns();

    // One type, one column: two types sharing one would make "what is this value" unanswerable
    // without reading the field, and reading the field is exactly what sorting cannot do.
    expect($columns)->toHaveCount(count(CustomFieldType::cases()))
        ->and(array_unique($columns))->toHaveCount(count(CustomFieldType::cases()));
});

it('names the column each type is stored in', function (CustomFieldType $type, string $column): void {
    expect($type->column())->toBe($column);
})->with([
    'text' => [CustomFieldType::Text, 'value_text'],
    'number' => [CustomFieldType::Number, 'value_number'],
    'date' => [CustomFieldType::Date, 'value_date'],
    'boolean' => [CustomFieldType::Boolean, 'value_boolean'],
    'select' => [CustomFieldType::Select, 'value_option_id'],
]);

it('validates each type by what it is', function (): void {
    expect(CustomFieldType::Number->rules())->toBe(['numeric'])
        ->and(CustomFieldType::Date->rules())->toBe(['date'])
        ->and(CustomFieldType::Boolean->rules())->toBe(['boolean'])
        ->and(CustomFieldType::Text->rules())->toBe(['string', 'max:255'])
        // A select is checked against the field's own options rather than by shape, so the rule
        // here only says "an id".
        ->and(CustomFieldType::Select->rules())->toBe(['uuid']);
});

it('turns what a form sends into what the column wants', function (): void {
    /*
     * A value arrives as a string whatever it is: "12.5" is a number, "2026-08-23" is a date,
     * and each column refuses the others.
     */
    expect(CustomFieldType::Number->normalise('12.5'))->toBe(12.5)
        ->and(CustomFieldType::Text->normalise('  Two days  '))->toBe('Two days')
        ->and(CustomFieldType::Date->normalise('2026-08-23'))->toEqual(CarbonImmutable::parse('2026-08-23'));
});

it('reads a date as the day rather than the moment', function (): void {
    // A date field answers "which day", and keeping the time would make two answers on one day
    // sort against each other.
    expect(CustomFieldType::Date->normalise('2026-08-23 17:45:00'))
        ->toEqual(CarbonImmutable::parse('2026-08-23')->startOfDay());
});

it('reads a checkbox the way a person means it', function (mixed $sent, bool $stored): void {
    expect(CustomFieldType::Boolean->normalise($sent))->toBe($stored);
})->with([
    'ticked' => ['1', true],
    'true' => ['true', true],
    'on' => ['on', true],
    // `(bool) "false"` is true, which is the wrong answer to every checkbox anybody has ever
    // unticked.
    'the string false' => ['false', false],
    'zero' => ['0', false],
    'nonsense' => ['maybe', false],
]);

it('treats nothing as a cleared answer', function (CustomFieldType $type): void {
    expect($type->normalise(null))->toBeNull()
        ->and($type->normalise(''))->toBeNull();
})->with([
    'text' => [CustomFieldType::Text],
    'number' => [CustomFieldType::Number],
    'date' => [CustomFieldType::Date],
    'boolean' => [CustomFieldType::Boolean],
    'select' => [CustomFieldType::Select],
]);

it('says which type needs an option list', function (): void {
    expect(CustomFieldType::Select->isSelect())->toBeTrue()
        ->and(CustomFieldType::Text->isSelect())->toBeFalse();
});
