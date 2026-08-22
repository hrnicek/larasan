<?php

declare(strict_types=1);

use App\Domain\Task\Ancestry\ParentChain;

/**
 * @param  array<string, string|null>  $parents
 * @return callable(string): ?string
 */
function parentResolver(array $parents): callable
{
    return fn (string $id): ?string => $parents[$id] ?? null;
}

it('refuses a task as its own parent', function (): void {
    expect(ParentChain::wouldCycle('a', 'a', parentResolver([])))->toBeTrue();
});

it('refuses a two-step loop', function (): void {
    // b is already a child of a, so putting a under b closes the loop.
    expect(ParentChain::wouldCycle('a', 'b', parentResolver(['b' => 'a'])))->toBeTrue();
});

it('refuses a longer loop', function (): void {
    $parents = ['b' => 'a', 'c' => 'b', 'd' => 'c'];

    expect(ParentChain::wouldCycle('a', 'd', parentResolver($parents)))->toBeTrue();
});

it('allows a parent that is not an ancestor', function (): void {
    $parents = ['b' => 'a', 'c' => null];

    expect(ParentChain::wouldCycle('b', 'c', parentResolver($parents)))->toBeFalse();
});

it('allows a sibling to become a parent', function (): void {
    $parents = ['b' => 'a', 'c' => 'a'];

    expect(ParentChain::wouldCycle('c', 'b', parentResolver($parents)))->toBeFalse();
});

it('stops rather than spinning when the existing data already loops', function (): void {
    // Corrupt data: b and c point at each other. The walk must terminate.
    $parents = ['b' => 'c', 'c' => 'b'];

    expect(ParentChain::wouldCycle('a', 'b', parentResolver($parents)))->toBeTrue();
});

it('counts the ancestors above a task', function (): void {
    $parents = ['b' => 'a', 'c' => 'b', 'd' => 'c'];

    expect(ParentChain::depthOf('a', parentResolver($parents)))->toBe(0)
        ->and(ParentChain::depthOf('b', parentResolver($parents)))->toBe(1)
        ->and(ParentChain::depthOf('d', parentResolver($parents)))->toBe(3);
});

it('states a maximum depth rather than leaving it to be discovered', function (): void {
    expect(ParentChain::MAX_DEPTH)->toBeGreaterThan(1);
});
