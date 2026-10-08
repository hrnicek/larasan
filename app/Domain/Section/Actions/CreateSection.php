<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Events\SectionCreated;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateSection
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, CreateSectionData $data): Section
    {
        if (! $project->allowsChangesBy($actor, Capability::SectionCreate)) {
            throw SectionException::cannotManageSections();
        }

        try {
            $section = $this->append($project, $data);
        } catch (UniqueConstraintViolationException) {
            // A concurrent append took the same slot; retry against the new tail.
            $section = $this->append($project, $data);
        }

        $this->events->dispatch(new SectionCreated($section->id, $project->id, $actor->id));

        return $section;
    }

    private function append(Project $project, CreateSectionData $data): Section
    {
        return DB::transaction(function () use ($project, $data): Section {
            // PostgreSQL rejects FOR UPDATE with an aggregate, so the tail row is locked instead.
            // reorder() is required because the relation already orders by position ascending.
            $last = $project->sections()->reorder('position', 'desc')->lockForUpdate()->value('position');

            $section = new Section([
                'name' => $data->name,
                'color' => $data->color,
                'position' => SparsePosition::append($last === null ? null : (int) $last),
            ]);

            $section->project_id = $project->id;
            $section->save();

            return $section;
        });
    }
}
