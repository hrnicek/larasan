<?php

declare(strict_types=1);

namespace App\Domain\Tag\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTag
{
    public function handle(Tag $tag, User $actor, ?string $name = null, ?AccentColor $color = null, bool $clearColor = false): Tag
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

        if ($color !== null || $clearColor) {
            $tag->color = $clearColor ? null : $color;
        }

        try {
            // A savepoint keeps a unique violation from aborting an enclosing PostgreSQL transaction.
            DB::transaction(fn () => $tag->save());
        } catch (UniqueConstraintViolationException) {
            throw TagException::nameIsTaken();
        }

        return $tag;
    }
}
