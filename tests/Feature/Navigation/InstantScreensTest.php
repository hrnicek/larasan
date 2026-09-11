<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Workspace\Models\Workspace;

/*
 * A link opens these screens instantly (Inertia v3): the client swaps to the component at once,
 * carrying only the props the page says are shared, and the shell draws the screen's skeleton
 * until the prop named here arrives — `resources/js/composables/usePendingScreen.ts` holds the
 * same four pairs. Both halves rest on the server. A page that stopped listing its shared keys
 * would leave an instant visit with nothing to carry over; an awaited prop that became shared
 * would end the skeleton before the screen had any props of its own to draw.
 */
dataset('instant screens', [
    'a project' => ['projects.show', 'projects/Show', 'project'],
    'the project list' => ['projects.index', 'projects/Index', 'allProjects'],
    'my tasks' => ['my-tasks.index', 'my-tasks/Index', 'tasks'],
    'the inbox' => ['inbox.index', 'inbox/Index', 'notifications'],
]);

it('lists its shared props and keeps the awaited one its own', function (string $route, string $component, string $awaits): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    $page = $this->actingAs($actor)
        ->get(route($route, $route === 'projects.show' ? $project : []))
        ->assertOk()
        ->viewData('page');

    expect($page['component'])->toBe($component)
        ->and($page['props'])->toHaveKey($awaits)
        ->and($page['sharedProps'])->toContain('auth', 'workspace', 'projects', 'unreadNotifications')
        ->and($page['sharedProps'])->not->toContain($awaits);
})->with('instant screens');

/*
 * The project skeleton draws its header from the sidebar's row for the project it is waiting on,
 * so the row has to carry what the header shows first.
 */
it('shares the sidebar row a project skeleton draws its header from', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);

    $page = $this->actingAs($actor)->get(route('dashboard'))->assertOk()->viewData('page');

    expect($page['props']['projects'][0])
        ->toMatchArray(['id' => $project->id, 'name' => 'Website relaunch'])
        ->toHaveKeys(['color', 'icon']);
});
