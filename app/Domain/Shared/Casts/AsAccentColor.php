<?php

declare(strict_types=1);

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\ValueObjects\AccentColor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<AccentColor, AccentColor|ProjectColor|string|null>
 */
class AsAccentColor implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?AccentColor
    {
        return is_string($value) ? AccentColor::tryFrom($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value instanceof AccentColor, $value instanceof ProjectColor => $value->value,
            is_string($value) => (new AccentColor($value))->value,
            default => null,
        };
    }
}
