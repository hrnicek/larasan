<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Models\RecentItem;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

final readonly class RecordRecentlyOpened
{
    public const KEPT = 20;

    public function handle(Workspace $workspace, User $actor, Model $subject): void
    {
        RecentItem::query()->upsert(
            [[
                'id' => (new RecentItem)->newUniqueId(),
                'user_id' => $actor->id,
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
     * `opened_at` has second precision, so `id` breaks ties.
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
