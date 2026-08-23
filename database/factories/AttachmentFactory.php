<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\File\Models\Attachable;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
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
