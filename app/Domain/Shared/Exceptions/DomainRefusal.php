<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

/**
 * A rule an Action refuses for every caller, carrying a message a user can read.
 *
 * The transport layer decides what a refusal looks like — that is why Actions throw instead
 * of calling `abort()` — and this interface is how it recognises one. Without it a refusal
 * the FormRequest could not pre-check reaches the browser as a 500: a legitimate "no"
 * rendered as a crash, which is what it did before TASK-080-012.
 *
 * Implemented rather than inherited, because each context's exception already extends
 * `DomainException` and a shared base class would flatten four independent hierarchies for
 * one marker.
 */
interface DomainRefusal {}
