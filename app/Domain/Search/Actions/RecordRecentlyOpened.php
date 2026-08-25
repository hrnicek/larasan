<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Models\RecentItem;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * Remember that somebody opened something.
 *
 * Two statements, and the count is the point: this runs on every screen that opens a task or a
 * project, so it is written as an upsert and a single delete rather than a read-then-write and a
 * read-then-delete. `ScreenQueryCountTest` is what holds that number honest.
 *
 * Idempotent by design: the unique index makes opening the same task twice one row moved rather
 * than two rows kept.
 *
 * The trim is here rather than in a scheduled command because the list has an owner and a size a
 * person can hold in their head — keeping the last twenty per workspace means the table cannot
 * grow into something that needs sweeping.
 */
final readonly class RecordRecentlyOpened
{
    public const KEPT = 20;

    public function handle(Workspace $workspace, User $actor, Model $subject): void
    {
        RecentItem::query()->upsert(
            [[
                'id' => (new RecentItem)->newUniqueId(),
                'user_id' => $actor->id,
                // A task moves between workspaces in no version of this application, but the
                // column is written on every open so a row cannot outlive its own truth.
                'workspace_id' => $workspace->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'opened_at' => now(),
            ]],
            ['user_id', 'subject_type', 'subject_id'],
            ['workspace_id', 'opened_at'],
        );

        $this->trim($workspace, $actor);
    }

    /**
     * Everything past the twentieth, in one statement.
     *
     * `opened_at` is a second-resolution timestamp, so two rows opened in the same second need a
     * second key or the trim would drop whichever one PostgreSQL felt like.
     */
    private function trim(Workspace $workspace, User $actor): void
    {
        RecentItem::query()
            ->where('user_id', $actor->id)
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('id', function (Builder $keep) use ($workspace, $actor): void {
                $keep->select('id')
                    ->from('recent_items')
                    ->where('user_id', $actor->id)
                    ->where('workspace_id', $workspace->id)
                    ->orderByDesc('opened_at')
                    ->orderByDesc('id')
                    ->limit(self::KEPT);
            })
            ->delete();
    }
}
