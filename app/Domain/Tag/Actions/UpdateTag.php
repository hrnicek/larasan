<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rename or recolour a tag.
 *
 * Renaming changes what the word means everywhere it is already applied, which is why it asks
 * `tag.manage` rather than the permission that puts one on a task.
 */
final readonly class UpdateTag
{
    public function handle(Tag $tag, User $actor, ?string $name = null, ?ProjectColor $color = null, bool $clearColor = false): Tag
    {
        if (! $tag->workspace->membershipFor($actor)?->allows(Capability::TagManage)) {
            throw TagException::cannotManageTags();
        }

        if ($name !== null) {
            $name = trim($name);

            if ($name === '') {
                throw TagException::nameIsEmpty();
            }

            $tag->name = $name;
        }

        // A colour can be set or removed; "not mentioned" is neither, which is why the caller
        // has to say which of the two a null means.
        if ($color !== null || $clearColor) {
            $tag->color = $clearColor ? null : $color;
        }

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
