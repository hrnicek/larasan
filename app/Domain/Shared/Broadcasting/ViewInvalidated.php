<?php

declare(strict_types=1);

namespace App\Domain\Shared\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Something changed that a screen is showing. The one broadcast this application makes.
 *
 * ADR-0008 makes the database authoritative and a broadcast a signal to refetch, so the
 * payload is deliberately thin: which channel, what changed, which subject, and who did it.
 * A thin payload is also the only one that is safe for **every** subscriber of a channel —
 * the moment a broadcast carries a field, somebody has to prove that everyone on the
 * channel may read that field, and the proof has to be redone every time the field changes.
 *
 * The channels are resolved where the change happens, never here. A task's placement decides
 * who may hear about it, and placement is only knowable at the moment of the event — see
 * `ChannelsForTask`.
 *
 * The retry bounds are deliberately tight. A board update delivered a minute after the change is
 * worse than one that never arrives: the client would have moved on, and ADR-0008 already says a
 * missed event is recovered by asking the server rather than by replaying. Three attempts two
 * seconds apart, and ten seconds to make each — well inside the worker's sixty and the queue's
 * ninety-second `retry_after`, so a slow broadcast can never be handed to a second worker.
 */
#[Tries(3)]
#[Backoff(2)]
#[Timeout(10)]
final readonly class ViewInvalidated implements ShouldBroadcast
{
    /**
     * @param  list<string>  $channels  channel names without the `private-` prefix Echo adds
     */
    public function __construct(
        public array $channels,
        public string $change,
        public string $subjectType,
        public string $subjectId,
        /** Null where the domain event records no actor, as a section or project update does. */
        public ?int $actorId,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $channel): Channel => new PrivateChannel($channel),
            $this->channels,
        );
    }

    public function broadcastAs(): string
    {
        return 'view.invalidated';
    }

    /**
     * Above `notifications` and `default` in the supervisor Horizon already runs (ADR-0008):
     * a board update nobody sees for thirty seconds is a board nobody trusts, and a slow
     * email must never be what delayed it.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'change' => $this->change,
            'subject' => ['type' => $this->subjectType, 'id' => $this->subjectId],
            'actorId' => $this->actorId,
        ];
    }
}
