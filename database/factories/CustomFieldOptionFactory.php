<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Shared\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldOption>
 */
class CustomFieldOptionFactory extends Factory
{
    protected $model = CustomFieldOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory()->ofType(CustomFieldType::Select),
            'label' => fake()->unique()->word(),
            'color' => null,
            'position' => 1,
        ];
    }

    public function of(CustomField $field, int $position = 1): self
    {
        return $this->state(fn (): array => [
            'custom_field_id' => $field->id,
            'position' => $position,
        ]);
    }

    public function labelled(string $label): self
    {
        return $this->state(fn (): array => ['label' => $label]);
    }
}
