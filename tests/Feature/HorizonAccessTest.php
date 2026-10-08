<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use App\Providers\HorizonServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * @param  list<string>  $operators
 */
function horizonGateIn(string $environment, array $operators = []): void
{
    app()->detectEnvironment(fn (): string => $environment);
    config(['horizon.operators' => $operators]);

    (new HorizonServiceProvider(app()))->boot();
}

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
    config(['horizon.operators' => []]);
});

it('denies an unauthenticated visitor', function (): void {
    horizonGateIn('production', ['ops@example.com']);

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('denies a workspace owner in production, however capable they are in their own workspace', function (): void {
    // Anyone can create and own a workspace, while job payloads span every tenant. See ADR-0011.
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace, WorkspaceRole::Owner);

    horizonGateIn('production');

    expect(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('workspace.manage', $workspace))->toBeTrue();
});

it('denies everyone when no operator is configured', function (): void {
    $user = User::factory()->create();

    horizonGateIn('production', []);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('denies someone who merely claims an operator address', function (): void {
    // Registration does not prove mailbox control, so an unverified address is never an operator.
    $claimant = User::factory()->unverified()->create(['email' => 'ops@example.com']);

    horizonGateIn('production', ['ops@example.com']);

    expect(Gate::forUser($claimant)->allows('viewHorizon'))->toBeFalse();
});

it('allows a configured operator, matching the address case-insensitively', function (): void {
    $operator = User::factory()->create(['email' => 'ops@example.com']);

    horizonGateIn('production', ['OPS@example.com']);

    expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeTrue();
});

it('allows any authenticated user while developing', function (string $environment): void {
    $user = User::factory()->create();

    horizonGateIn($environment, []);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
})->with(['local', 'testing']);
