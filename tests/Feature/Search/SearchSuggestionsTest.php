<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\GlobalSearchQuery;
use App\Domain\Shared\Enums\SearchKind;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;

it('requires authentication', function (): void {
    $this->getJson(route('search.suggestions', ['q' => 'login']))->assertUnauthorized();
});

it('answers with every kind at once', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, user: User::factory()->create(['name' => 'Actor Zero', 'email' => 'actor@pinned.test']));
    $task = Task::factory()->in($workspace)->create(['title' => 'Invoice the client']);
    Project::factory()->in($workspace)->create(['name' => 'Invoice rewrite']);
    memberOf($workspace, user: User::factory()->create(['name' => 'Invoice Person', 'email' => 'invoice@pinned.test']));
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice']))
        ->assertOk()
        ->assertJsonPath('meta.term', 'invoice')
        ->assertJsonPath('meta.kind', null)
        ->assertJsonPath('meta.degraded', false)
        ->assertJsonCount(1, 'results.tasks')
        ->assertJsonCount(1, 'results.projects')
        ->assertJsonCount(1, 'results.people')
        ->assertJsonCount(1, 'results.messages');
});

it('answers with one kind when the tab asks for one', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Invoice the client']);
    Project::factory()->in($workspace)->create(['name' => 'Invoice rewrite']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice', 'kind' => 'tasks']))
        ->assertOk()
        ->assertJsonPath('meta.kind', 'tasks')
        ->assertJsonCount(1, 'results.tasks')
        ->assertJsonMissingPath('results.projects');
});

it('refuses a kind it does not have', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice', 'kind' => 'invoices']))
        ->assertUnprocessable();
});

it('refuses a term longer than anybody typed', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => str_repeat('a', 201)]))
        ->assertUnprocessable();
});

it('gives every kind back empty for an empty term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Invoice the client']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions'))
        ->assertOk()
        ->assertJsonCount(0, 'results.tasks')
        ->assertJsonCount(0, 'results.projects')
        ->assertJsonCount(0, 'results.people')
        ->assertJsonCount(0, 'results.messages');
});

it('shows nothing from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $elsewhere = Workspace::factory()->create();
    Task::factory()->in($elsewhere)->create(['title' => 'Invoice the client']);
    Project::factory()->in($elsewhere)->create(['name' => 'Invoice rewrite']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice']))
        ->assertOk()
        ->assertJsonCount(0, 'results.tasks')
        ->assertJsonCount(0, 'results.projects');
});

it('answers tasks from PostgreSQL when the engine cannot be reached', function (): void {
    $log = Log::spy();

    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Invoice the client']);
    Project::factory()->in($workspace)->create(['name' => 'Invoice rewrite']);

    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:9']);
    app()->forgetInstance(EngineManager::class);

    $answer = $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice']))
        ->assertOk()
        ->assertJsonPath('meta.degraded', true)
        ->assertJsonCount(1, 'results.tasks')
        ->assertJsonCount(0, 'results.projects')
        ->json();

    expect($answer['results']['tasks'][0]['title'])->toBe('Invoice the client');
    // Matched by message: each kind falls back separately, and the degraded path logs a warning of its own.
    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'Search fell back for one kind.')
        ->times(count(SearchKind::cases()));
});

it('stops a held-down key', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (range(1, 120) as $ignored) {
        $this->actingAs($actor)->getJson(route('search.suggestions', ['q' => 'invoice']))->assertOk();
    }

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice']))
        ->assertStatus(429);
});

it('names the kinds one place, and the query answers exactly those', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $answer = app(GlobalSearchQuery::class)($workspace, $actor, 'invoice');

    expect(array_keys($answer['results']))->toBe(['tasks', 'projects', 'people', 'messages', 'pages']);
});

it('answers the empty field the palette opens with', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    // `?q=` reaches the request as null.
    $this->actingAs($actor)
        ->getJson(route('search.suggestions').'?q=&kind=')
        ->assertOk()
        ->assertJsonPath('meta.term', '')
        ->assertJsonCount(0, 'results.tasks');
});

it('reads an empty kind as no kind at all', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Invoice the client']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions').'?q=invoice&kind=')
        ->assertOk()
        ->assertJsonPath('meta.kind', null)
        ->assertJsonCount(1, 'results.tasks');
});
