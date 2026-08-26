<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Carbon\CarbonImmutable;

it('names one column per type, and lists each column once', function (): void {
    /*
     * A type has exactly one column; a column may serve several types. `email`, `phone` and
     * `link` are text with a format rather than a new kind of storage — they sort as text, so a
     * column each would buy no ordering and cost an index each on the largest table in the
     * schema (TASK-240-007 amends `docs/architecture/database.md`).
     */
    foreach (CustomFieldType::cases() as $type) {
        expect($type->column())->toBeIn(CustomFieldType::columns());
    }

    $columns = CustomFieldType::columns();

    /*
     * Distinct, and this is the assertion that matters: `task_custom_field_values_one_value_check`
     * is built from this list, so `value_text` counted once per type sharing it would make a
     * single text answer look like four and refuse every write.
     */
    expect($columns)->toBe(array_values(array_unique($columns)))
        ->and($columns)->toHaveCount(5);
});

it('stores the text-shaped types in the text column', function (CustomFieldType $type): void {
    expect($type->column())->toBe('value_text');
})->with([
    'text' => [CustomFieldType::Text],
    'email' => [CustomFieldType::Email],
    'phone' => [CustomFieldType::Phone],
    'link' => [CustomFieldType::Link],
]);

it('names the column each type is stored in', function (CustomFieldType $type, string $column): void {
    expect($type->column())->toBe($column);
})->with([
    'text' => [CustomFieldType::Text, 'value_text'],
    'number' => [CustomFieldType::Number, 'value_number'],
    'date' => [CustomFieldType::Date, 'value_date'],
    'boolean' => [CustomFieldType::Boolean, 'value_boolean'],
    'select' => [CustomFieldType::Select, 'value_option_id'],
    'email' => [CustomFieldType::Email, 'value_text'],
    'phone' => [CustomFieldType::Phone, 'value_text'],
    'link' => [CustomFieldType::Link, 'value_text'],
]);

it('validates the text-shaped types by their shape rather than by their length alone', function (): void {
    expect(CustomFieldType::Email->rules())->toBe(['email', 'max:255'])
        // The column is 255 wide, so the length is the column's rather than a guess.
        ->and(CustomFieldType::Link->rules())->toBe(['url', 'max:255'])
        /*
         * Permissive on purpose: numbers are written a dozen ways across countries and this
         * project has no phone-number library, so the rule keeps out prose and lets a person
         * write the number the way their colleagues will recognise it.
         */
        ->and(CustomFieldType::Phone->rules())->toBe(['string', 'max:32', 'regex:/^[0-9+()\\-.\\/ ]{3,32}$/']);
});

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
        ->and(CustomFieldType::Email->normalise('  someone@example.com  '))->toBe('someone@example.com')
        ->and(CustomFieldType::Link->normalise(' https://example.com/a '))->toBe('https://example.com/a')
        ->and(CustomFieldType::Phone->normalise('  +420 123 456 789 '))->toBe('+420 123 456 789')
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
    'email' => [CustomFieldType::Email],
    'phone' => [CustomFieldType::Phone],
    'link' => [CustomFieldType::Link],
]);

it('says which type needs an option list', function (): void {
    expect(CustomFieldType::Select->isSelect())->toBeTrue()
        ->and(CustomFieldType::Text->isSelect())->toBeFalse();
});
