<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\TaskResults;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\EngineManager;

/**
 * What the collection engine cannot prove: typo tolerance, and that a stale index cannot widen
 * an answer (ADR-0016). These run against a real Meilisearch and skip themselves when none
 * answers, so the suite still passes on a machine that has never started one.
 */
function meilisearchAnswers(): bool
{
    try {
        return Http::timeout(2)
            ->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')
            ->successful();
    } catch (Throwable) {
        return false;
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

    config(['scout.driver' => 'meilisearch', 'scout.prefix' => 'pm_suite_']);
    app()->forgetInstance(EngineManager::class);

    // The index and its settings, as a deployment would create them: a filter on an attribute
    // Meilisearch was not told about does not error, it answers with the wrong set.
    try {
        app(EngineManager::class)->engine()->createIndex('pm_suite_tasks');
    } catch (Throwable) {
        // Already there, from the test before this one.
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
})->with([
    'a person who has mistyped does not know whether the thing they are looking for exists',
]);

it('does not hand over a task the index still holds and the database no longer allows', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $task->searchable();

    $found = untilIndexed(fn (): array => app(TaskResults::class)($workspace, $actor, 'login'));
    expect($found)->toHaveCount(1);

    // The permission changes and the index is deliberately not told — the case a queue that is
    // behind, a failed job or a restarted container produces in production.
    Task::withoutSyncingToSearch(function () use ($project): void {
        $project->update(['visibility' => 'private']);
    });

    expect(app(TaskResults::class)($workspace, $actor, 'login'))->toBe([])
        ->and(Task::search('login')->keys()->all())->toContain($task->id);
})->with([
    'the index can make an answer shorter; it must never be able to make one wider (ADR-0016)',
]);
