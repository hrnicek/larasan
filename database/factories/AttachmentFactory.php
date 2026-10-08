<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'attachable_type' => 'task',
            'attachable_id' => fn (): string => (string) Task::factory()->create()->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Attachment $attachment): void {
            if (isset($attachment->position)) {
                return;
            }

            $last = Attachment::query()
                ->where('attachable_type', $attachment->attachable_type)
                ->where('attachable_id', $attachment->attachable_id)
                ->max('position');

            $attachment->position = SparsePosition::append($last === null ? null : (int) $last);
        });
    }

    public function attaching(File $file, Model&Attachable $subject): self
    {
        return $this->state(fn (): array => [
            'file_id' => $file->id,
            'attachable_type' => 'task',
            'attachable_id' => $subject->getKey(),
        ]);
    }
}
