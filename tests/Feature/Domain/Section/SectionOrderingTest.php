<?php

declare(strict_types=1);

use App\Domain\Section\Actions\DeleteSection;
use App\Domain\Section\Actions\MoveSection;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Ordering\SparsePosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('survives repeated insertion at the same point', function (): void {
    [$project, $actor] = projectEditableBy();
    $first = addSection($project, $actor, 'First');
    $last = addSection($project, $actor, 'Last');

    foreach (range(1, 20) as $round) {
        $section = addSection($project, $actor, "Round {$round}");
        app(MoveSection::class)->handle($section, $actor, $first);
    }

    $positions = $project->sections()->pluck('position')->all();
    $names = $project->sections()->pluck('name')->all();

    expect($positions)->toHaveCount(22)
        ->and(array_unique($positions))->toHaveCount(22)
        ->and($positions)->toBe(array_values(collect($positions)->sort()->all()))
        ->and($names[0])->toBe('First')
        ->and($names[1])->toBe('Round 20')
        ->and($names[21])->toBe('Last');
});

it('normalises rather than handing out a colliding position', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    $a->forceFill(['position' => 500])->save();
    $b->forceFill(['position' => 501])->save();
    $c->forceFill(['position' => 900])->save();

    app(MoveSection::class)->handle($c, $actor, $a);

    $positions = $project->sections()->pluck('position')->all();

    expect($project->sections()->pluck('name')->all())->toBe(['A', 'C', 'B'])
        ->and($positions[1] - $positions[0])->toBeGreaterThanOrEqual(SparsePosition::MINIMUM_GAP)
        ->and($positions[2] - $positions[1])->toBeGreaterThanOrEqual(SparsePosition::MINIMUM_GAP);
});

it('recovers when the slot it computed was taken between the read and the write', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');

    // Simulates a concurrent writer taking the computed slot, which handle() must retry past.
    $injected = false;

    Section::updating(function (Section $section) use (&$injected): void {
        if ($injected || ! $section->isDirty('position')) {
            return;
        }

        $injected = true;

        DB::table('sections')->insert([
            'id' => (string) Str::uuid7(),
            'project_id' => $section->project_id,
            'name' => 'Squatter',
            'color' => null,
            'position' => $section->position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    app(MoveSection::class)->handle($c, $actor, $a);

    $positions = $project->sections()->pluck('position')->all();

    expect($injected)->toBeTrue()
        ->and($project->sections()->pluck('name')->all())->toBe(['A', 'C', 'B'])
        ->and(array_unique($positions))->toHaveCount(3);
});

it('leaves the order intact when a section in the middle is deleted', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $c = addSection($project, $actor, 'C');
    $positions = [$a->position, $c->position];

    app(DeleteSection::class)->handle($b, $actor);

    expect($project->sections()->pluck('name')->all())->toBe(['A', 'C'])
        ->and($project->sections()->pluck('position')->all())->toBe($positions);
});

it('lets a new section take the slot a deleted one held', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');
    $taken = $b->position;

    app(DeleteSection::class)->handle($b, $actor);
    $replacement = addSection($project, $actor, 'B again');

    expect($replacement->position)->toBe($taken)
        ->and($a->fresh()?->position)->toBeLessThan($replacement->position);
});

it('keeps two projects ordering independently', function (): void {
    [$mine, $actor] = projectEditableBy();
    [$theirs, $otherActor] = projectEditableBy();

    $mineFirst = addSection($mine, $actor, 'Mine A');
    addSection($mine, $actor, 'Mine B');
    $theirsFirst = addSection($theirs, $otherActor, 'Theirs A');
    $theirsSecond = addSection($theirs, $otherActor, 'Theirs B');

    app(MoveSection::class)->handle($theirsSecond, $otherActor, null);

    expect($mine->sections()->pluck('name')->all())->toBe(['Mine A', 'Mine B'])
        ->and($theirs->sections()->pluck('name')->all())->toBe(['Theirs B', 'Theirs A'])
        ->and($mineFirst->fresh()?->position)->toBe(Section::POSITION_GAP)
        ->and($theirsFirst->fresh()?->position)->toBe(Section::POSITION_GAP);
});
