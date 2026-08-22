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

/**
 * Renaming and recolouring, which are the only things about a section that are not its
 * position. Moving it is `MoveSection`: a move is a different question with different
 * concurrency, and folding the two together would put a row lock on a rename.
 */
final readonly class RenameSection
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Section $section, User $actor, UpdateSectionData $data): Section
    {
        if (! $section->project->allowsChangesBy($actor, Capability::SectionUpdate)) {
            throw SectionException::cannotManageSections();
        }

        /*
         * The colour is nullable, so null means "clear it" — the lesson from Phase 040's
         * review, where filtering nulls made the field write-once. The name is not
         * nullable and the Data object types it as a string, so it has no such ambiguity.
         */
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
