<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use App\Domain\Shared\ValueObjects\AccentColor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One of the eight palette names, or a hex.
 *
 * A rule rather than `Rule::enum(ProjectColor::class)` since TASK-260-005: the picker offers a
 * ninth swatch, and the column accepts what it produces. The message names both halves, because
 * "the selected colour is invalid" tells somebody nothing about which colours are not.
 */
class IsAccentColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! AccentColor::isValid(mb_strtolower(trim($value)))) {
            $fail('The :attribute must be a palette colour or a hex colour like #3f7d5a.');
        }
    }
}
