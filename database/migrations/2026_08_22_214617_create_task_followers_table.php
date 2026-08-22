<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_followers', function (Blueprint $table): void {
            /*
             * A uuid key, unlike the sketch in `docs/architecture/database.md`, which listed
             * only the three columns. Every Action and event in this project carries the id
             * of the row it acted on, and a composite-key model is a special case that pays
             * for itself only at a scale a follower list never reaches (ADR-0001).
             */
            $table->uuid('id')->primary();

            // Both ends cascade. A follow is a live subscription rather than history: when
            // the task or the account goes, there is nothing left to notify about.
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Following twice is following once. The Action is idempotent and this is what
            // makes that true for every caller, including a raw insert.
            $table->unique(['task_id', 'user_id']);

            /*
             * When, and nothing else. A follow is not edited — it exists or it does not — so
             * an `updated_at` would be a column that never changes and a reader would have to
             * wonder what it meant.
             */
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_followers');
    }
};
