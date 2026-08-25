<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_stars', function (Blueprint $table): void {
            // The shape `task_followers` and `project_stars` already have: a uuid key, because
            // every Action here carries the id of the row it acted on (ADR-0001).
            $table->uuid('id')->primary();

            // Both ends cascade. A star is one person's shortcut to one task: when either goes,
            // there is nothing left to point at.
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Starring twice is starring once. The Action is idempotent and this is what makes
            // that true for every caller, including a raw insert.
            $table->unique(['task_id', 'user_id']);

            /*
             * "Which tasks has this person starred" is the whole Starred tab, and PostgreSQL does
             * not index the referencing side of a foreign key. The unique index above already
             * covers lookups that start from the task.
             */
            $table->index('user_id');

            /** When, and nothing else: a star is not edited, it exists or it does not. */
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_stars');
    }
};
