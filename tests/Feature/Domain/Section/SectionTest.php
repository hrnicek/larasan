<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Support\Facades\DB;

it('stores the colour by name and reads it back as the enum', function (): void {
    $section = Section::factory()->create(['color' => ProjectColor::Rose]);

    expect(DB::table('sections')->where('id', $section->id)->value('color'))->toBe('rose')
        ->and($section->fresh()?->color)->toBe(ProjectColor::Rose);
});

it('belongs to its project', function (): void {
    $project = Project::factory()->create();
    $section = Section::factory()->in($project)->create();

    expect($section->project->is($project))->toBeTrue();
});

it('lists the project sections in position order, not insertion order', function (): void {
    $project = Project::factory()->create();
    Section::factory()->in($project)->at(3 * Section::POSITION_GAP)->create(['name' => 'Third']);
    Section::factory()->in($project)->at(Section::POSITION_GAP)->create(['name' => 'First']);
    Section::factory()->in($project)->at(2 * Section::POSITION_GAP)->create(['name' => 'Second']);

    expect($project->sections()->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

it('keeps another project out of the list', function (): void {
    $project = Project::factory()->create();
    Section::factory()->in($project)->create(['name' => 'Mine']);
    Section::factory()->create(['name' => 'Theirs']);

    expect($project->sections()->pluck('name')->all())->toBe(['Mine']);
});

it('reads a factory section without touching an attribute it never loaded', function (): void {
    $section = Section::factory()->create();

    expect($section->color)->toBeNull()
        ->and($section->position)->toBe(Section::POSITION_GAP)
        ->and($section->name)->not->toBeEmpty();
});
