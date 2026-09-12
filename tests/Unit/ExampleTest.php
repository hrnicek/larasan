<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;

test('a role answers the same capability question every time it is asked', function (): void {
    $role = WorkspaceRole::Member;

    expect($role->capabilities())->toBe($role->capabilities())
        ->and($role->capabilities())->not->toBeEmpty();
});
