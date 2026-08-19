<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('registers a gate for every capability', function (): void {
    foreach (Capability::cases() as $capability) {
        expect(Gate::has($capability->value))->toBeTrue();
    }
});

it('answers the capability from the membership row', function (WorkspaceRole $role): void {
    [$workspace, $actor] = workspaceWith($role);
    $gate = Gate::forUser($actor);

    foreach (Capability::cases() as $capability) {
        expect($gate->allows($capability->value, $workspace))
            ->toBe($role->allows($capability));
    }
})->with([
    'owner' => WorkspaceRole::Owner,
    'admin' => WorkspaceRole::Admin,
    'member' => WorkspaceRole::Member,
    'guest' => WorkspaceRole::Guest,
]);

it('grants nothing to someone who is not a member', function (): void {
    $workspace = Workspace::factory()->create();
    $gate = Gate::forUser(User::factory()->create());

    foreach (Capability::cases() as $capability) {
        expect($gate->allows($capability->value, $workspace))->toBeFalse();
    }
});

it('grants nothing while the membership is not active', function (WorkspaceMembershipStatus $status): void {
    [$workspace, $actor] = workspaceWith(WorkspaceRole::Owner, $status);
    $gate = Gate::forUser($actor);

    foreach (Capability::cases() as $capability) {
        expect($gate->allows($capability->value, $workspace))->toBeFalse();
    }
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('does not carry a role from one workspace into another', function (): void {
    $owned = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = memberOf($owned, WorkspaceRole::Owner);
    memberOf($other, WorkspaceRole::Guest, user: $owner);

    $gate = Gate::forUser($owner);

    expect($gate->allows(Capability::WorkspaceManage->value, $owned))->toBeTrue()
        ->and($gate->allows(Capability::WorkspaceManage->value, $other))->toBeFalse()
        ->and($gate->allows(Capability::CommentCreate->value, $other))->toBeTrue();
});

it('keeps role comparisons inside the capability layer', function (): void {
    /*
     * The rule ADR-0010 states: nothing compares a role by hand. Enforced by reading the
     * source rather than by discipline, because the first hand-rolled comparison is the
     * one that quietly disagrees with the enum.
     */
    $allowed = [
        'app/Domain/Shared/Enums/WorkspaceRole.php',
        'app/Domain/Workspace/Models/WorkspaceMembership.php',
    ];

    $offenders = [];

    foreach (phpFilesIn(base_path('app')) as $file) {
        $relative = str_replace(base_path().'/', '', $file);

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (preg_match('/(===|!==|==)\s*WorkspaceRole::|WorkspaceRole::\w+\s*(===|!==|==)/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}
