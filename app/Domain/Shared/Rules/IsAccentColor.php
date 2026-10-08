<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use App\Domain\Shared\ValueObjects\AccentColor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IsAccentColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! AccentColor::isValid(mb_strtolower(trim($value)))) {
            $fail('The :attribute must be a palette colour or a hex colour like #3f7d5a.');
        }
    }
}
