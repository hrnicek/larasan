<?php

declare(strict_types=1);

namespace App\Domain\Account\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * What the account Actions refuse for every caller. The FormRequest catches these first over
 * HTTP; the Action still checks, because a console command arrives without one.
 */
final class AccountException extends DomainException implements DomainRefusal
{
    public static function unknownAvatarPreset(): self
    {
        return new self('That picture is not one of the ones on offer.');
    }

    public static function unsupportedAvatarType(): self
    {
        return new self('A profile picture must be a JPEG, PNG or WebP image.');
    }

    public static function couldNotStoreAvatar(): self
    {
        return new self('That picture could not be stored. Your profile picture is unchanged.');
    }
}
