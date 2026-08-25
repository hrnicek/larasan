<?php

declare(strict_types=1);

namespace App\Domain\Page\Data;

/**
 * A document, and the version of the page the writer's editor last read.
 */
final readonly class SavePageContentData
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(public array $content, public int $version) {}
}
