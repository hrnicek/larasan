<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

use App\Http\Requests\Comment\StoreCommentRequest;

/**
 * The subject and the author are absent by design: they are arguments to the Action, decided
 * by who is asking and what they are asking about, never payload a request could carry.
 */
final readonly class CreateCommentData
{
    public function __construct(public string $body) {}

    public static function fromRequest(StoreCommentRequest $request): self
    {
        return new self(body: (string) $request->string('body'));
    }
}
