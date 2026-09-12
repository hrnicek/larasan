<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateTag
{
    public function handle(Workspace $workspace, User $actor, string $name, ?AccentColor $color = null): Tag
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
            // A savepoint keeps a unique violation from aborting an enclosing PostgreSQL transaction.
            DB::transaction(fn () => $tag->save());
        } catch (QueryException $exception) {
            throw TagException::nameIsTaken();
        }

        return $tag;
    }
}
