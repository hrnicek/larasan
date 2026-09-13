<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\SearchTasksQuery;
use App\Domain\Search\Queries\TaskResults;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeilisearchClient;

function meilisearchAnswers(): bool
{
    static $answers = [];

    $host = rtrim((string) config('scout.meilisearch.host'), '/');

    try {
        return $answers[$host] ??= Http::timeout(2)->get($host.'/health')->successful();
    } catch (Throwable) {
        return $answers[$host] = false;
    }
}

/** Meilisearch indexes asynchronously; a search run in the same millisecond finds nothing. */
function untilIndexed(callable $search, int $attempts = 40): mixed
{
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $found = $search();

        if ($found !== [] && $found !== null && (! is_countable($found) || count($found) > 0)) {
            return $found;
        }

        usleep(100_000);
    }

    return $search();
}

beforeEach(function (): void {
    if (! meilisearchAnswers()) {
        $this->markTestSkipped('No Meilisearch on '.config('scout.meilisearch.host').'.');
    }

    $prefix = 'pm_suite_'.(ParallelTesting::token() ?: '0').'_';

    config(['scout.driver' => 'meilisearch', 'scout.prefix' => $prefix]);
    app()->forgetInstance(EngineManager::class);

    // Meilisearch does not reject a filter on an undeclared attribute; it returns the wrong set.
    try {
        app(EngineManager::class)->engine()->createIndex($prefix.'tasks');
    } catch (Throwable) {
        // The index already exists.
    }

    Artisan::call('scout:sync-index-settings');
});

afterEach(function (): void {
    if (config('scout.driver') === 'meilisearch' && meilisearchAnswers()) {
        Task::removeAllFromSearch();
    }
});

it('forgives a typo the database never would', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen'])->searchable();

    $results = untilIndexed(fn (): array => app(TaskResults::class)($workspace, $actor, 'logni'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Fix the login screen');
});

it('does not hand over a task the index still holds and the database no longer allows', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $task->searchable();

    $found = untilIndexed(fn (): array => app(TaskResults::class)($workspace, $actor, 'login'));
    expect($found)->toHaveCount(1);

    // Leaves the index stale, as a lagging queue or a failed job would.
    Task::withoutSyncingToSearch(function () use ($project): void {
        $project->update(['visibility' => 'private']);
    });

    expect(app(TaskResults::class)($workspace, $actor, 'login'))->toBe([])
        ->and(Task::search('login')->keys()->all())->toContain($task->id);
});

it('lets the search screen forgive a typo, and counts what it found exactly', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (['Fix the login screen', 'Log in as a guest'] as $title) {
        Task::factory()->in($workspace)->create(['title' => $title])->searchable();
    }

    $answer = untilIndexed(function () use ($workspace, $actor): array {
        $result = app(SearchTasksQuery::class)($workspace, $actor, 'logni');

        return $result['tasks'] === [] ? [] : [$result];
    })[0] ?? null;

    expect($answer)->not->toBeNull()
        ->and($answer['meta']['degraded'])->toBeFalse()
        ->and($answer['meta']['capped'])->toBeFalse()
        ->and($answer['meta']['total'])->toBe(count($answer['tasks']));
});

it('says so when it had to answer from the database instead', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    config(['scout.meilisearch.host' => 'http://127.0.0.1:9']);
    // Scout binds the client as a singleton, so the manager alone would keep the old host.
    app()->forgetInstance(MeilisearchClient::class);
    app()->forgetInstance(EngineManager::class);

    $answer = app(SearchTasksQuery::class)($workspace, $actor, 'login');

    expect($answer['meta']['degraded'])->toBeTrue()
        ->and($answer['tasks'])->toHaveCount(1);
});
