<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Field '.Str::lower(Str::random(8)),
            'type' => CustomFieldType::Text,
        ];
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function ofType(CustomFieldType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
