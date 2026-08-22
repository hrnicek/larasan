<?php

declare(strict_types=1);

namespace App\Domain\Activity\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Shared\Enums\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The one way a line of history is written.
 *
 * No authorization: an activity records something the domain has already allowed and done, and
 * asking again would be asking whether something that happened was permitted.
 *
 * The properties are ids and values, never a serialised model — a feed line rendered from a
 * snapshot would show a name that has since changed as though it never did.
 */
final readonly class RecordActivity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function handle(
        string $workspaceId,
        Model $subject,
        ?int $actorId,
        ActivityType $type,
        array $properties = [],
    ): Activity {
        $activity = new Activity;

        $activity->workspace_id = $workspaceId;
        $activity->subject_type = (string) Relation::getMorphAlias($subject::class);
        $activity->subject_id = (string) $subject->getKey();
        $activity->actor_id = $actorId;
        $activity->type = $type;
        $activity->properties = $properties;
        $activity->save();

        return $activity;
    }
}
