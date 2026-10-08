<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectColor;
use App\Http\Requests\Section\MoveSectionRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->post('section-probe/{project}', fn (StoreSectionRequest $request, Project $project) => response()->json([
        'name' => $request->string('name')->toString(),
        'color' => $request->enum('color', ProjectColor::class)?->value,
    ]));

    Route::middleware('web')->put('section-probe/{section}', fn (UpdateSectionRequest $request, Section $section) => response()->json([
        'name' => $request->string('name')->toString(),
    ]));

    Route::middleware('web')->put('section-probe/{section}/move', fn (MoveSectionRequest $request, Section $section) => response()->json([
        'after' => $request->string('after')->value() ?: null,
    ]));
});

it('accepts a section a project editor asked for', function (): void {
    [$project, $actor] = projectEditableBy();

    $this->actingAs($actor)
        ->postJson("section-probe/{$project->id}", ['name' => 'Backlog', 'color' => ProjectColor::Sky->value])
        ->assertOk()
        ->assertJson(['name' => 'Backlog', 'color' => 'sky']);
});

it('refuses creation to someone who may not shape the project', function (): void {
    [$project, $editor] = projectEditableBy();
    $stranger = viewerOf($project);
    expect($editor->id)->not->toBe($stranger->id);

    $this->actingAs($stranger)
        ->postJson("section-probe/{$project->id}", ['name' => 'Backlog'])
        ->assertForbidden();
});

it('rejects a section with no name or a colour outside the palette', function (array $payload, string $field): void {
    [$project, $actor] = projectEditableBy();

    $this->actingAs($actor)
        ->postJson("section-probe/{$project->id}", $payload)
        ->assertJsonValidationErrorFor($field);
})->with([
    'no name' => [[], 'name'],
    'colour off the palette' => [['name' => 'Backlog', 'color' => 'fuchsia'], 'color'],
]);

it('accepts a rename from an editor and refuses one from a viewer', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'Backlog');

    $this->actingAs($actor)
        ->putJson("section-probe/{$section->id}", ['name' => 'Up next'])
        ->assertOk()
        ->assertJson(['name' => 'Up next']);

    $viewer = viewerOf($project);

    $this->actingAs($viewer)
        ->putJson("section-probe/{$section->id}", ['name' => 'Up next'])
        ->assertForbidden();
});

it('accepts a move behind a sibling and to the front', function (): void {
    [$project, $actor] = projectEditableBy();
    $a = addSection($project, $actor, 'A');
    $b = addSection($project, $actor, 'B');

    $this->actingAs($actor)
        ->putJson("section-probe/{$b->id}/move", ['after' => $a->id])
        ->assertOk()
        ->assertJson(['after' => $a->id]);

    $this->actingAs($actor)
        ->putJson("section-probe/{$b->id}/move", ['after' => null])
        ->assertOk()
        ->assertJson(['after' => null]);
});

it('rejects an anchor from another project', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'A');
    [$otherProject, $otherActor] = projectEditableBy();
    $foreign = addSection($otherProject, $otherActor, 'Theirs');

    $this->actingAs($actor)
        ->putJson("section-probe/{$section->id}/move", ['after' => $foreign->id])
        ->assertJsonValidationErrorFor('after');
});

it('rejects a section as its own anchor', function (): void {
    [$project, $actor] = projectEditableBy();
    $section = addSection($project, $actor, 'A');

    $this->actingAs($actor)
        ->putJson("section-probe/{$section->id}/move", ['after' => $section->id])
        ->assertJsonValidationErrorFor('after');
});
