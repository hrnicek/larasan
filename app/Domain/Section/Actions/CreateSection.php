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
        if (! self::allows($project, $actor)) {
            throw SectionException::cannotManageSections();
        }

        try {
            $section = $this->append($project, $data);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two people added a column at the same moment and computed the same slot.
             * `UNIQUE(project_id, position)` turned that into an error rather than two
             * sections in one place (ADR-0009); the second one reads the tail again and
             * appends after the winner.
             */
            $section = $this->append($project, $data);
        }

        $this->events->dispatch(new SectionCreated($section->id, $project->id, $actor->id));

        return $section;
    }

    /**
     * Both halves of the answer, as everything in a project does: the workspace capability
     * says the actor may shape projects at all, the access level says they may shape this
     * one (ADR-0006 with ADR-0010). Editing the columns is editing the project, so Editor
     * is enough — managing it is not required.
     */
    public static function allows(Project $project, User $actor): bool
    {
        return $project->isVisibleTo($actor)
            && $project->workspace->membershipFor($actor)?->allows(Capability::SectionCreate) === true
            && $project->memberFor($actor)?->access_level->canEdit() === true;
    }

    private function append(Project $project, CreateSectionData $data): Section
    {
        return DB::transaction(function () use ($project, $data): Section {
            /*
             * The tail row is read and locked inside the transaction, so a second append
             * waits rather than computing the same slot. PostgreSQL refuses `FOR UPDATE`
             * with an aggregate, which is why this orders and takes one row instead of
             * asking for `max()` — the same restriction `Workspace::isLastOwner()` met.
             *
             * An empty project has no row to lock, so two first appends can still collide;
             * the unique constraint catches that and `handle()` retries.
             */
            $last = $project->sections()->orderByDesc('position')->lockForUpdate()->value('position');

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
