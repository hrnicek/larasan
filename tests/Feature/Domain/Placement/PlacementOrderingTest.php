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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A column with `$count` cards in it, titled 'A', 'B', 'C'… so the order reads.
 *
 * A list rather than a collection: every test here indexes it by position, and an offset on a
 * collection is a `TaskProjectMembership|null` that none of them mean.
 *
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

    // C goes between A and B, expressed as "after A" — the client sends an id, not a number.
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

    // Sparse positions exist so a drag does not rewrite the tail (ADR-0009).
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

    // Two cards one apart: there is no midpoint left between them, which is the case
    // `SparsePosition::MINIMUM_GAP` exists to notice before the sequence corrupts.
    $cards[0]->forceFill(['position' => 10])->save();
    $cards[1]->forceFill(['position' => 11])->save();

    moveTo($cards[2], $actor, $section, PlacementTarget::after($cards[0]->refresh()));

    $positions = $section->placements()->pluck('position')->all();

    // The column was respread and the card then took a midpoint inside it, so the result is
    // not the even spread itself — what matters is that every neighbour is far enough apart
    // for the next drag to have a midpoint of its own.
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

    /*
     * Rows are parked in negative space before being written to their final positions: a
     * rewrite that wrote final positions directly would collide with a row it had not moved
     * yet, and the slot guard would abort the whole move.
     */
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

    /*
     * Stand-in for the concurrent case, which a single-process test cannot stage: the moment
     * before the move writes its position, somebody else takes that exact slot. The slot
     * guard turns it into an error and `handle()` retries — without the retry the exception
     * escapes and this test fails.
     */
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

    /*
     * The squatter row goes down with the transaction the unique violation aborted, so what
     * survives is the retry's work: three cards, three distinct slots, in the order asked
     * for. Without the retry, the violation escapes and nothing survives at all.
     */
    expect($injected)->toBeTrue()
        ->and(orderIn($section))->toBe(['A', 'C', 'B'])
        ->and(array_unique($positions))->toHaveCount(3);
});
