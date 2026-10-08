<?php

declare(strict_types=1);

namespace App\Domain\Page\Data;

use App\Domain\Page\Models\Page;

final readonly class CreatePageData
{
    public function __construct(public string $title) {}

    public static function titled(?string $title): self
    {
        $title = trim((string) $title);

        return new self($title === '' ? Page::UNTITLED : $title);
    }
}
