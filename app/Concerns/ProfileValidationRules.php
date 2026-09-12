<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait ProfileValidationRules
{
    /**
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                if (! is_string($value)) {
                    return;
                }

                $taken = User::query()
                    ->whereRaw('lower(email) = ?', [Str::lower($value)])
                    ->when($userId !== null, fn (Builder $query): Builder => $query->whereKeyNot($userId))
                    ->exists();

                if ($taken) {
                    $fail('validation.unique')->translate();
                }
            },
        ];
    }
}
