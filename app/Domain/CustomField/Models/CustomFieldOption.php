<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Models;

use App\Domain\Shared\Casts\AsAccentColor;
use App\Domain\Shared\ValueObjects\AccentColor;
use Database\Factories\CustomFieldOptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $custom_field_id
 * @property string $label
 * @property AccentColor|null $color
 * @property int $position
 * @property-read CustomField $field
 */
#[UseFactory(CustomFieldOptionFactory::class)]
class CustomFieldOption extends Model
{
    /** @use HasFactory<CustomFieldOptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['label', 'color', 'position'];

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color' => AsAccentColor::class,
            'position' => 'integer',
        ];
    }
}
