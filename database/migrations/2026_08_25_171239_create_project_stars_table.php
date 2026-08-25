<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stars', function (Blueprint $table): void {
            // A uuid key for the reason `task_followers` has one: every Action in this project
            // carries the id of the row it acted on, and a composite-key model is a special
            // case that pays for itself only at a scale a starred list never reaches (ADR-0001).
            $table->uuid('id')->primary();

            // Both ends cascade. A star is one person's shortcut to one project: when either
            // goes, there is nothing left to point at.
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Starring twice is starring once. The Action is idempotent and this is what makes
            // that true for every caller, including a raw insert.
            $table->unique(['project_id', 'user_id']);

            /*
             * "Which projects has this person starred" is asked on every request in the
             * application, from the sidebar, and PostgreSQL does not index the referencing
             * side of a foreign key. The unique index above already covers the other direction.
             */
            $table->index('user_id');

            /*
             * When, and nothing else. A star is not edited — it exists or it does not — so an
             * `updated_at` would be a column that never changes.
             */
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stars');
    }
};
