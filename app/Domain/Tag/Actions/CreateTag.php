<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Add a word to a workspace's vocabulary.
 *
 * `tag.manage` rather than `task.update`: applying a tag is editing a task, but *inventing* one
 * changes what everybody else's filters mean.
 *
 * The uniqueness is the database's, and the race is real — two people can create "Bug" in the
 * same second — so the unique index is caught rather than pre-checked with a query that would be
 * out of date by the time it returned.
 */
final readonly class CreateTag
{
    public function handle(Workspace $workspace, User $actor, string $name, ?ProjectColor $color = null): Tag
    {
        if (! $workspace->membershipFor($actor)?->allows(Capability::TagManage)) {
            throw TagException::cannotManageTags();
        }

        $name = trim($name);

        if ($name === '') {
            throw TagException::nameIsEmpty();
        }

        $tag = new Tag(['name' => $name, 'color' => $color]);
        $tag->workspace_id = $workspace->id;

        try {
            /*
             * Inside its own transaction so the failure is a rolled-back savepoint rather than
             * a poisoned connection: PostgreSQL refuses every later statement on a transaction
             * that has seen an error, and this Action is often called inside one.
             */
            DB::transaction(fn () => $tag->save());
        } catch (QueryException $exception) {
            throw TagException::nameIsTaken();
        }

        return $tag;
    }
}
