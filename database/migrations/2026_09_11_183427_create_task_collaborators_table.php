<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collaborators', function (Blueprint $table): void {
            // A uuid key, as `task_followers` has: every Action and event here carries the id of
            // the row it acted on (ADR-0001).
            $table->uuid('id')->primary();

            // Both ends cascade. A collaboration is a person on a piece of work; with either
            // gone there is nobody left doing it, and the activity feed keeps the history.
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Being on a task twice is being on it once, for every caller including a raw insert.
            // The unique index leads with `task_id`, which is also the panel's read.
            $table->unique(['task_id', 'user_id']);

            // "What am I collaborating on" reads by person, and the release job after a
            // member's removal reads the same way.
            $table->index('user_id');

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_collaborators');
    }
};
