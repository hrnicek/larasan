<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;

// Mirrors `resources/js/composables/usePendingScreen.ts`; an awaited prop that became shared would end the skeleton early.
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
        // `settings/security` requires a recent password confirmation.
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route, $parameters))
        ->assertOk()
        ->viewData('page');

    expect($page['component'])->toBe($component)
        ->and($page['props'])->toHaveKey($awaits)
        ->and($page['sharedProps'])->toContain('auth', 'workspace', 'projects', 'unreadNotifications')
        ->and($page['sharedProps'])->not->toContain($awaits);
})->with('instant screens');

it('shares the sidebar row a project skeleton draws its header from', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);

    $page = $this->actingAs($actor)->get(route('dashboard'))->assertOk()->viewData('page');

    expect($page['props']['projects'][0])
        ->toMatchArray(['id' => $project->id, 'name' => 'Website relaunch'])
        ->toHaveKeys(['color', 'icon']);
});
