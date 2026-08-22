<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Direct, for the reason a comment's is direct: an activity is polymorphic, so
            // there is no single aggregate to join through (ADR-0005).
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            // Written out rather than `uuidMorphs()`, whose own index would be a prefix of the
            // feed index below and therefore paid for on every write and never chosen.
            $table->string('subject_type');
            $table->uuid('subject_id');

            /*
             * Nullable and nulled on delete, as a comment's author is: the history of a task
             * must survive the account that made it, or deleting a person quietly rewrites
             * what happened.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // What happened, as a short stable string. `properties` carries whatever that kind
            // of event needs — the field that changed, the value it had — rather than a column
            // per event type that is null for every other one.
            $table->string('type');
            $table->json('properties');

            /*
             * `created_at` alone, no `updated_at`. An activity records something that already
             * happened; there is nothing to update, and a column suggesting otherwise invites
             * somebody to try. `task_followers` follows the same rule.
             */
            $table->timestamp('created_at')->nullable(false);

            // The feed's read, in the feed's order.
            $table->index(['subject_type', 'subject_id', 'created_at']);

            /*
             * PostgreSQL does not index the referencing side of a foreign key, so without
             * these an account or workspace deletion scans the whole table — which for a
             * history table is the largest one in the schema.
             */
            $table->index('actor_id');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
