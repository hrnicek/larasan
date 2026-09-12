<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Data;

use App\Domain\CustomField\Models\CustomField;

final readonly class FieldSort
{
    public function __construct(
        public CustomField $field,
        public bool $descending = false,
    ) {}

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }
}
