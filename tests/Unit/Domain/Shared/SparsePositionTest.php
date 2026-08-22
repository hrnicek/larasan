<?php

declare(strict_types=1);

use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;

it('starts an empty set at the gap and appends by it', function (): void {
    expect(SparsePosition::append(null))->toBe(SparsePosition::GAP)
        ->and(SparsePosition::append(SparsePosition::GAP))->toBe(2 * SparsePosition::GAP);
});

it('takes the midpoint between two neighbours', function (): void {
    expect(SparsePosition::between(1000, 2000))->toBe(1500);
});

it('treats a null neighbour as the end of the set', function (): void {
    expect(SparsePosition::between(2000, null))->toBe(2000 + SparsePosition::GAP)
        ->and(SparsePosition::between(null, 2000))->toBe(1000);
});

it('refuses a midpoint that would sit on top of its neighbour', function (?int $before, ?int $after): void {
    expect(fn (): int => SparsePosition::between($before, $after))->toThrow(PositionsNeedNormalisation::class);
})->with([
    'neighbours one apart' => [1000, 1001],
    'neighbours at the minimum gap' => [1000, 1000 + SparsePosition::MINIMUM_GAP],
    'no room at the front' => [null, 4],
]);

it('reports room before handing out a position', function (): void {
    expect(SparsePosition::hasRoomBetween(1000, 1001))->toBeFalse()
        ->and(SparsePosition::hasRoomBetween(1000, 1000 + SparsePosition::MINIMUM_GAP * 2))->toBeTrue()
        // The end of the set always has room: appending cannot run out.
        ->and(SparsePosition::hasRoomBetween(PHP_INT_MAX - SparsePosition::GAP, null))->toBeTrue();
});

it('normalises to an even spread', function (): void {
    expect(SparsePosition::spread(3))->toBe([
        SparsePosition::GAP,
        2 * SparsePosition::GAP,
        3 * SparsePosition::GAP,
    ])->and(SparsePosition::spread(0))->toBe([]);
});

it('parks rows outside the range it is rewriting', function (): void {
    $parking = array_map(SparsePosition::parking(...), [0, 1, 2]);

    expect($parking)->toBe([-1, -2, -3])
        // Nothing a spread produces can collide with a parked row mid-rewrite.
        ->and(array_intersect($parking, SparsePosition::spread(3)))->toBe([]);
});
