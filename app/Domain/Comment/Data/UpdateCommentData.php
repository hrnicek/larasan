<?php

declare(strict_types=1);

namespace App\Domain\Comment\Data;

use App\Http\Requests\Comment\UpdateCommentRequest;

/**
 * A comment has one editable field, so this carries one. No `$fields` map as `UpdateTaskData`
 * needs: there is nothing here that could be absent rather than null.
 */
final readonly class UpdateCommentData
{
    public function __construct(public string $body) {}

    public static function fromRequest(UpdateCommentRequest $request): self
    {
        return new self(body: (string) $request->string('body'));
    }
}
