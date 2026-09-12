<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Section\Data\UpdateSectionData;
use App\Domain\Section\Events\SectionUpdated;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class RenameSection
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Section $section, User $actor, UpdateSectionData $data): Section
    {
        if (! $section->project->allowsChangesBy($actor, Capability::SectionUpdate)) {
            throw SectionException::cannotManageSections();
        }

        $section->fill(['name' => $data->name, 'color' => $data->color]);

        $changed = array_keys($section->getDirty());

        if ($changed === []) {
            return $section;
        }

        $section->save();

        $this->events->dispatch(new SectionUpdated($section->id, $section->project_id, $changed));

        return $section;
    }
}
