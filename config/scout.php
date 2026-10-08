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
    | Visibility is decided by the hydration query, not the engine. See ADR-0016.
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
    | Separates deployments sharing one Meilisearch instance; it is not a tenancy boundary.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | Indexing runs on the `search` queue of the default queue connection.
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
    | Sync only after the surrounding database transaction commits.
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
    | Soft-deleted records are removed from the index.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | Only used by Algolia.
    |
    */

    'identify' => false,

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Index settings applied by `scout:sync-index-settings`, keyed by searchable model.
    | Every attribute a query filters on must be declared filterable.
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Task::class => [
                'filterableAttributes' => ['workspace_id', 'completed'],
                'sortableAttributes' => ['created_at'],
                'searchableAttributes' => ['title', 'description'],
            ],
            Project::class => [
                'filterableAttributes' => ['workspace_id', 'archived'],
                'searchableAttributes' => ['name', 'slug', 'description'],
            ],
            // Users span workspaces, so tenant scoping is the membership join in `PersonResults`.
            User::class => [
                'filterableAttributes' => [],
                'searchableAttributes' => ['name', 'email'],
            ],
            Page::class => [
                'filterableAttributes' => ['workspace_id', 'project_id'],
                'searchableAttributes' => ['title', 'text'],
            ],
            Comment::class => [
                'filterableAttributes' => ['workspace_id', 'commentable_type'],
                'searchableAttributes' => ['body'],
            ],
        ],
    ],

];
