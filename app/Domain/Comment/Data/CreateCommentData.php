<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

/**
 * The subject and the author are absent by design: they are arguments to the Action, decided
 * by who is asking and what they are asking about, never payload a request could carry.
 */
final readonly class CreateCommentData
{
    public function __construct(public string $body) {}

    // `fromRequest()` arrives with the request itself (TASK-110-004). A constructor that
    // referenced a class nobody had written yet would be a type nobody could check.
}
