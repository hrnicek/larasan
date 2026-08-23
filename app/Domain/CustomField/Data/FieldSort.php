<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Data;

use App\Domain\CustomField\Models\CustomField;

/**
 * "Order this list by that field."
 *
 * A pair rather than two loose arguments, because the direction means nothing without the field
 * and a caller that passed one without the other would be asking for a list ordered by nothing
 * in particular.
 */
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
