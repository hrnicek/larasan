<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Models;

use App\Domain\Shared\Enums\ProjectColor;
use Database\Factories\CustomFieldOptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice a `select` field offers.
 *
 * @property string $id
 * @property string $custom_field_id
 * @property string $label
 * @property ProjectColor|null $color
 * @property int $position
 * @property-read CustomField $field
 */
#[UseFactory(CustomFieldOptionFactory::class)]
class CustomFieldOption extends Model
{
    /** @use HasFactory<CustomFieldOptionFactory> */
    use HasFactory, HasUuids;

    // The field an option belongs to is decided by the Action from what is being edited.
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
            'color' => ProjectColor::class,
            'position' => 'integer',
        ];
    }
}
