<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Data\MentionedBody;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Commentable;
use App\Domain\Comment\Support\Mentions;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Everybody a comment names, checked, and written down under the name they have now.
 *
 * A mention is a user id arriving from outside — the composer offers people, but the payload is
 * whatever the request says — so it gets the question an assignee gets: a live member of the
 * subject's workspace who can read the subject. Refused rather than dropped, because a comment that
 * quietly lost a name leaves its author believing somebody was told who was not.
 *
 * The name in the token is replaced with the account's own, so what was typed next to an id
 * decides nothing about what the thread shows.
 */
final readonly class ResolveMentions
{
    public function handle(Model&Commentable $subject, string $body): MentionedBody
    {
        $ids = Mentions::idsIn($body);

        if ($ids === []) {
            return new MentionedBody($body, []);
        }

        if (count($ids) > Mentions::LIMIT) {
            throw CommentException::tooManyMentions();
        }

        $people = Workspace::query()->findOrFail($subject->workspaceId())
            ->members()
            ->whereKey($ids)
            ->get();

        if ($people->count() !== count($ids) || $people->contains(fn (User $person): bool => $person->cannot('view', $subject))) {
            throw CommentException::cannotMention();
        }

        return new MentionedBody(
            Mentions::withNames($body, $people->mapWithKeys(fn (User $person): array => [$person->id => $person->name])->all()),
            $ids,
        );
    }
}
