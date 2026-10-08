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
        ];
    }

    public function configure(): static
    {
        // A batch is made in full before any row is saved, so the database alone cannot see its siblings.
        /** @var array<string, int> $made */
        $made = [];

        return $this->afterMaking(function (CustomFieldOption $option) use (&$made): void {
            if (isset($option->position)) {
                return;
            }

            $last = CustomFieldOption::query()->where('custom_field_id', $option->custom_field_id)->max('position');

            $option->position = $made[$option->custom_field_id] = max((int) $last, $made[$option->custom_field_id] ?? 0) + 1;
        });
    }

    public function of(CustomField $field, ?int $position = null): self
    {
        $factory = $this->state(fn (): array => ['custom_field_id' => $field->id]);

        return $position === null ? $factory : $factory->at($position);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    public function labelled(string $label): self
    {
        return $this->state(fn (): array => ['label' => $label]);
    }
}
