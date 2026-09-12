<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

use App\Http\Requests\Comment\UpdateCommentRequest;

final readonly class UpdateCommentData
{
    public function __construct(public string $body) {}

    public static function fromRequest(UpdateCommentRequest $request): self
    {
        return new self(body: (string) $request->string('body'));
    }
}
