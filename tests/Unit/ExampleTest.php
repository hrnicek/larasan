<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;

/*
 * The starter kit's placeholder asserted `expect(true)->toBeTrue()`, which the Pest
 * PHPStan rules correctly report as an assertion that cannot fail. Kept as a file rather
 * than deleted (tests are not removed without approval), it now asserts the one thing
 * tests/Unit exists for: logic with no database behind it.
 */
test('a role answers the same capability question every time it is asked', function (): void {
    $role = WorkspaceRole::Member;

    expect($role->capabilities())->toBe($role->capabilities())
        ->and($role->capabilities())->not->toBeEmpty();
});
