<?php

declare(strict_types=1);

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
            //
        ],
    ],

];
