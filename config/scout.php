<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | Meilisearch matches and ranks; PostgreSQL decides what may be seen (ADR-0016).
    |
    | The suite runs on "collection", set in phpunit.xml: it exercises the same Scout API and
    | the same query() callback the reach rule lives in, without a service to start. An
    | installation with no search engine may set "null" and lose the palette rather than the
    | application.
    |
    | Supported: "meilisearch", "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | One Meilisearch instance may serve several deployments of this application. The prefix
    | keeps a staging import from overwriting production's index of the same name — it is not
    | a tenancy boundary, which is `workspace_id` and the hydration query.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | Indexing is queued, so saving a task is not held open by an HTTP call to a search
    | service. The connection is left to the environment's default — the suite queues
    | synchronously and must not reach for Redis — and the queue is named so Horizon can
    | watch it (config/horizon.php).
    |
    */

    'queue' => [
        'connection' => env('SCOUT_QUEUE_CONNECTION'),
        'queue' => env('SCOUT_QUEUE_NAME', 'search'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | Sync after the transaction commits, never inside it: a rolled-back write must not leave
    | a row in the index that PostgreSQL no longer has.
    |
    */

    'after_commit' => true,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | Used by `scout:import` and `scout:flush`.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | A soft-deleted record leaves the index. Search is how people find work, and work in the
    | trash is not work; the trash has its own screen.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | Algolia's analytics only, and this application does not run Algolia.
    |
    */

    'identify' => false,

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | `index-settings` is what `scout:sync-index-settings` writes to the engine. Every index
    | declares `workspace_id` filterable, because every query sends that filter: it keeps one
    | tenant's typing from ranking against another tenant's data. It is not what makes the
    | answer correct — the hydration query is (ADR-0016).
    |
    | The key of each entry is the searchable model, resolved to its index name by Scout.
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Task::class => [
                /*
                 * `workspace_id` because every query sends it; `completed` because the palette
                 * and the search screen both offer "still open" as a filter, and Meilisearch
                 * silently returns the wrong set when asked to filter on an attribute it was
                 * not told about.
                 */
                'filterableAttributes' => ['workspace_id', 'completed'],
                'sortableAttributes' => ['created_at'],
                // The id is a key, not a word: leaving it searchable makes a UUID somebody
                // pasted match every task whose id happens to share a run of characters.
                'searchableAttributes' => ['title', 'description'],
            ],
            Project::class => [
                'filterableAttributes' => ['workspace_id', 'archived'],
                'searchableAttributes' => ['name', 'slug', 'description'],
            ],
            /*
             * No `workspace_id`: a person belongs to several workspaces, so their document has
             * no tenant to filter on. The boundary is the join to `workspace_memberships` in
             * `PersonResults` — the one place it can be, and the one place it is.
             */
            User::class => [
                'filterableAttributes' => [],
                'searchableAttributes' => ['name', 'email'],
            ],
            /*
             * The project as well as the workspace: a page is found inside the project it was
             * written in, and "the pages in this project" must not mean reading every page in
             * the tenant to find out which.
             */
            Page::class => [
                'filterableAttributes' => ['workspace_id', 'project_id'],
                'searchableAttributes' => ['title', 'text'],
            ],
            Comment::class => [
                // The subject's type, so "messages on tasks" does not mean reading every
                // comment in the workspace to find out which are on tasks.
                'filterableAttributes' => ['workspace_id', 'commentable_type'],
                'searchableAttributes' => ['body'],
            ],
        ],
    ],

];
