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
 * The payload carries no model fields because every subscriber on a channel receives it.
 * The timeout stays below the queue's retry_after so a slow broadcast never runs twice.
 * See ADR-0008.
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
