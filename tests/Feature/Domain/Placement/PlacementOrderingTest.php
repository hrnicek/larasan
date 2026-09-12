<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{Section, list<TaskProjectMembership>, User, Project}
 */
function column(int $count = 3): array
{
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();

    $cards = [];

    foreach (range(0, $count - 1) as $index) {
        $task = Task::factory()->in($workspace)->create(['title' => chr(65 + $index)]);
        $placement = attach($task, $project, $actor);
        moveInto($placement, $actor, $section);
        $cards[] = $placement->refresh();
    }

    return [$section, $cards, $actor, $project];
}

function moveTo(
    TaskProjectMembership $placement,
    User $actor,
    ?Section $section,
    PlacementTarget $target,
): TaskProjectMembership {
    return app(MoveTaskInProject::class)->handle($placement, $actor, $section, $target);
}

/**
 * @return array<int, string>
 */
function orderIn(Section $section): array
{
    return $section->placements()->with('task')->get()
        ->map(fn (TaskProjectMembership $card): string => (string) $card->task->title)
        ->all();
}

it('places a card after the one the user dropped it on', function (): void {
    [$section, $cards, $actor] = column();

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]));

    expect(orderIn($section))->toBe(['A', 'C', 'B']);
});

it('places a card at the front of the column', function (): void {
    [$section, $cards, $actor] = column();

    moveTo($cards[2], $actor, $section, PlacementTarget::front());

    expect(orderIn($section))->toBe(['C', 'A', 'B']);
});

it('places a card at the end of the column', function (): void {
    [$section, $cards, $actor] = column();

    moveTo($cards[0], $actor, $section, PlacementTarget::end());

    expect(orderIn($section))->toBe(['B', 'C', 'A']);
});

it('writes one row for a move', function (): void {
    [$section, $cards, $actor] = column();
    $untouched = [$cards[0]->position, $cards[1]->position];

    moveTo($cards[2], $actor, $section, PlacementTarget::front());

    expect([$cards[0]->refresh()->position, $cards[1]->refresh()->position])->toBe($untouched);
});

it('takes the midpoint between two neighbours', function (): void {
    [$section, $cards, $actor] = column();

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]));

    expect($cards[2]->refresh()->position)
        ->toBe(intdiv(SparsePosition::GAP + 2 * SparsePosition::GAP, 2));
});

it('does nothing when the card is already in that slot', function (): void {
    [$section, $cards, $actor] = column();
    $before = $cards[1]->position;

    moveTo($cards[1], $actor, $section, PlacementTarget::after($cards[0]));

    expect($cards[1]->refresh()->position)->toBe($before)
        ->and(orderIn($section))->toBe(['A', 'B', 'C']);
});

it('normalises the column when the neighbours have closed up', function (): void {
    [$section, $cards, $actor] = column();

    $cards[0]->forceFill(['position' => 10])->save();
    $cards[1]->forceFill(['position' => 11])->save();

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]->refresh()));

    $positions = $section->placements()->pluck('position')->all();

    // The column is respread first, then the moved card takes the midpoint of the first gap.
    expect(orderIn($section))->toBe(['A', 'C', 'B'])
        ->and($positions)->toBe([SparsePosition::GAP, 98304, 2 * SparsePosition::GAP])
        ->and(SparsePosition::hasRoomBetween($positions[0], $positions[1]))->toBeTrue()
        ->and(SparsePosition::hasRoomBetween($positions[1], $positions[2]))->toBeTrue();
});

it('keeps every card while it normalises', function (): void {
    [$section, $cards, $actor] = column(4);

    foreach ([10, 11, 12] as $index => $position) {
        $cards[$index]->forceFill(['position' => $position])->save();
    }

    moveTo($cards[3], $actor, $section, PlacementTarget::after($cards[0]->refresh()));

    // Normalising parks rows at negative positions first, or the slot guard would abort the move.
    expect($section->placements()->count())->toBe(4)
        ->and(orderIn($section))->toBe(['A', 'D', 'B', 'C']);
});

it('refuses an anchor from another column', function (): void {
    [$section, $cards, $actor, $project] = column();
    $elsewhere = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create();
    $stranger = $cards[0];
    moveTo($stranger, $actor, $elsewhere, PlacementTarget::end());

    expect(fn (): TaskProjectMembership => moveTo($cards[2], $actor, $section, PlacementTarget::after($stranger->refresh())))
        ->toThrow(PlacementException::class, 'That card is not in this column.');

    expect(orderIn($section))->toBe(['B', 'C']);
});

it('refuses an anchor from another project', function (): void {
    [$section, $cards, $actor] = column();
    [$otherWorkspace, $otherProject, $otherActor] = placeableProject();
    $foreign = attach(Task::factory()->in($otherWorkspace)->create(), $otherProject, $otherActor);

    expect(fn (): TaskProjectMembership => moveTo($cards[2], $actor, $section, PlacementTarget::after($foreign)))
        ->toThrow(PlacementException::class, 'That card is not in this column.');
});

it('refuses a card placed after itself', function (): void {
    [$section, $cards, $actor] = column();

    expect(fn (): TaskProjectMembership => moveTo($cards[1], $actor, $section, PlacementTarget::after($cards[1])))
        ->toThrow(PlacementException::class, 'A task cannot be placed after itself.');
});

it('orders the ungrouped bucket the same way', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    $cards = array_map(fn (string $title): TaskProjectMembership => attach(
        Task::factory()->in($workspace)->create(['title' => $title]),
        $project,
        $actor,
    ), ['A', 'B', 'C']);

    moveTo($cards[2], $actor, null, PlacementTarget::front());

    expect($project->placements()->with('task')->orderBy('position')->get()->pluck('task.title')->all())
        ->toBe(['C', 'A', 'B']);
});

it('moves a card into another column at a chosen place', function (): void {
    [$section, $cards, $actor, $project] = column();
    $target = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create();

    moveTo($cards[0], $actor, $target, PlacementTarget::end());
    moveTo($cards[1], $actor, $target, PlacementTarget::front());

    expect(orderIn($target))->toBe(['B', 'A'])
        ->and(orderIn($section))->toBe(['C']);
});

it('recovers when the slot it computed was taken between the read and the write', function (): void {
    [$section, $cards, $actor, $project] = column();

    // Simulates a concurrent writer taking the computed slot, which handle() must retry past.
    $injected = false;

    TaskProjectMembership::updating(function (TaskProjectMembership $placement) use (&$injected, $project, $section): void {
        if ($injected || ! $placement->isDirty('position')) {
            return;
        }

        $injected = true;

        DB::table('task_project_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => Task::factory()->in($project->workspace)->create(['title' => 'Squatter'])->id,
            'project_id' => $placement->project_id,
            'section_id' => $section->id,
            'position' => $placement->position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]));

    $positions = $section->placements()->pluck('position')->all();

    // The squatter is rolled back with the aborted transaction, so only the retry's writes survive.
    expect($injected)->toBeTrue()
        ->and(orderIn($section))->toBe(['A', 'C', 'B'])
        ->and(array_unique($positions))->toHaveCount(3);
});

it('moves the card when its slot after normalising is the number it held before', function (): void {
    [$section, $cards, $actor] = column();

    $cards[0]->forceFill(['position' => 10])->save();
    $cards[1]->forceFill(['position' => 11])->save();
    $cards[2]->forceFill(['position' => 98304])->save();

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]->refresh()));

    expect(orderIn($section))->toBe(['A', 'C', 'B']);
});

it('locks the project row before any card, whichever way the card crosses', function (): void {
    [$section, $cards, $actor, $project] = column();
    $other = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create();

    $statementsWhile = function (callable $move): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $move();

        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    };

    $moves = [
        fn (): TaskProjectMembership => moveTo($cards[0], $actor, $other, PlacementTarget::end()),
        fn (): TaskProjectMembership => moveTo($cards[0]->refresh(), $actor, $section, PlacementTarget::end()),
    ];

    foreach ($moves as $move) {
        $statements = $statementsWhile($move);

        $projectLock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "projects"')
            && str_ends_with($sql, 'for no key update'));

        $firstCardLock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, '"task_project_memberships"')
            && (str_ends_with($sql, 'for update') || str_starts_with($sql, 'update')));

        expect($projectLock)->toBeInt()
            ->and($firstCardLock)->toBeInt()
            ->and($projectLock)->toBeLessThan($firstCardLock);
    }

    expect(orderIn($section))->toBe(['B', 'C', 'A']);
});
