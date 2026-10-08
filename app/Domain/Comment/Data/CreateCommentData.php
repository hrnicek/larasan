<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

use App\Http\Requests\Comment\StoreCommentRequest;

final readonly class CreateCommentData
{
    public function __construct(public string $body) {}

    public static function fromRequest(StoreCommentRequest $request): self
    {
        return new self(body: (string) $request->string('body'));
    }
}
