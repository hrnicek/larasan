<?php

declare(strict_types=1);

namespace App\Domain\Project\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * Invariants the project Actions refuse for every caller. Transport layers translate
 * these; a FormRequest catches most of them first, and the Action still checks, because
 * a console command or a queued job arrives without one.
 */
final class ProjectException extends DomainException implements DomainRefusal
{
    public static function cannotManageProject(): self
    {
        return new self('You do not have permission to change this project.');
    }

    public static function cannotCreateProjects(): self
    {
        return new self('You do not have permission to create projects in this workspace.');
    }
}
