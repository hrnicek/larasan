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

    /**
     * The position cannot be a value in `definition()`: it depends on what is already attached to
     * the subject, which is only known once the other attributes have been resolved. Appending
     * here is what `AttachFile` does, so a factory-built list reads in the same order a real one
     * does — and `UNIQUE(attachable_type, attachable_id, position)` means guessing would fail
     * loudly on the second row.
     */
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

    /**
     * A file attached to a subject, both in the same workspace — a file in one workspace hanging
     * from a subject in another is a row the domain will never create.
     */
    public function attaching(File $file, Model&Attachable $subject): self
    {
        return $this->state(fn (): array => [
            'file_id' => $file->id,
            'attachable_type' => 'task',
            'attachable_id' => $subject->getKey(),
        ]);
    }
}
