<?php

declare(strict_types=1);

namespace App\Domain\Page\Data;

use App\Domain\Page\Models\Page;

/**
 * The project and the parent are arguments to the Action, resolved from the route and checked
 * against the actor's reach — never payload the Action trusts.
 */
final readonly class CreatePageData
{
    public function __construct(public string $title) {}

    /**
     * A page with no name is `Untitled`, not an empty row in the tree.
     */
    public static function titled(?string $title): self
    {
        $title = trim((string) $title);

        return new self($title === '' ? Page::UNTITLED : $title);
    }
}
