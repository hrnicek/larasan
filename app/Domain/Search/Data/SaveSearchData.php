<?php

declare(strict_types=1);

namespace App\Domain\Search\Data;

use App\Domain\Shared\Enums\SearchKind;
use App\Http\Requests\Search\StoreSavedSearchRequest;

/**
 * The owner and the workspace are absent by design: they are arguments to the Action, decided by
 * who is asking and where they are, never payload a form could carry.
 */
final readonly class SaveSearchData
{
    /**
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     */
    public function __construct(
        public string $name,
        public string $term,
        public ?SearchKind $kind = null,
        public array $filters = [],
    ) {}

    public static function fromRequest(StoreSavedSearchRequest $request): self
    {
        return new self(
            name: $request->string('name')->trim()->toString(),
            term: $request->string('term')->trim()->toString(),
            kind: $request->enum('kind', SearchKind::class),
            filters: $request->filters(),
        );
    }
}
