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

        // Names are rewritten from the accounts so a typed name cannot pass for someone else.
        return new MentionedBody(
            Mentions::withNames($body, $people->mapWithKeys(fn (User $person): array => [$person->id => $person->name])->all()),
            $ids,
        );
    }
}
