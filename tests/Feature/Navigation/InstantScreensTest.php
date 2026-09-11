<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;

/*
 * A link opens these screens instantly (Inertia v3): the client swaps to the component at once,
 * carrying only the props the page says are shared, and a skeleton is drawn until the prop named
 * here arrives — `resources/js/composables/usePendingScreen.ts` holds the same pairs. Both halves
 * rest on the server. A page that stopped listing its shared keys would leave an instant visit
 * with nothing to carry over; an awaited prop that became shared would end the skeleton before the
 * screen had any props of its own to draw.
 */
dataset('instant screens', [
    'a project' => ['projects.show', 'project', 'projects/Show', 'project'],
    'the project list' => ['projects.index', null, 'projects/Index', 'allProjects'],
    "a project's settings" => ['projects.edit', 'project', 'projects/Settings', 'project'],
    'my tasks' => ['my-tasks.index', null, 'my-tasks/Index', 'tasks'],
    'the inbox' => ['inbox.index', null, 'inbox/Index', 'notifications'],
    "a task's own page" => ['tasks.show', 'task', 'tasks/Show', 'task'],
    'a page of a project' => ['pages.show', 'page', 'pages/Show', 'page'],
    'search' => ['search.index', null, 'search/Index', 'tasks'],
    'the workspace list' => ['workspaces.index', null, 'workspaces/Index', 'invitations'],
    'profile settings' => ['profile.edit', null, 'settings/Profile', 'avatarPresets'],
    'workspace settings' => ['workspaces.edit', null, 'settings/Workspace', 'can'],
    'members' => ['workspaces.members', null, 'settings/Members', 'invitations'],
    'fields' => ['custom-fields.index', null, 'settings/Fields', 'fields'],
    'tags' => ['tags.index', null, 'settings/Tags', 'tags'],
    'security' => ['security.edit', null, 'settings/Security', 'passwordRules'],
    'appearance' => ['appearance.edit', null, 'settings/Appearance', 'uiThemes'],
]);

it('lists its shared props and keeps the awaited one its own', function (string $route, ?string $subject, string $component, string $awaits): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    $parameters = match ($subject) {
        'project' => $project,
        'task' => Task::factory()->in($workspace)->create(),
        'page' => Page::factory()->in($project)->create(),
        default => [],
    };

    $page = $this->actingAs($actor)
        // `settings/security` asks for the password again; a fresh confirmation stands in for it.
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route, $parameters))
        ->assertOk()
        ->viewData('page');

    expect($page['component'])->toBe($component)
        ->and($page['props'])->toHaveKey($awaits)
        ->and($page['sharedProps'])->toContain('auth', 'workspace', 'projects', 'unreadNotifications')
        ->and($page['sharedProps'])->not->toContain($awaits);
})->with('instant screens');

/*
 * The project skeletons draw their header from the sidebar's row for the project they wait on, so
 * the row has to carry what the header shows first.
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
