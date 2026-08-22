<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_project_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Both ends cascade: a placement cannot outlive the task it places or the
            // project it places it in. Deleting a project removes its placements and no
            // tasks — that asymmetry is the point of ADR-0003.
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            /*
             * Nullable: a task can be in a project without being in a column — the list
             * view's ungrouped bucket, and where a card lands when it is dragged out of one
             * (ADR-0004). Nulled on delete so that deleting a column moves its placements
             * rather than taking them with it, even when the delete does not go through
             * `DeleteSection` (Phase 050 review).
             */
            $table->foreignUuid('section_id')->nullable()->constrained()->nullOnDelete();

            // Sparse, as sections are (ADR-0009): a drag writes one row.
            $table->integer('position');

            $table->timestamps();

            /*
             * A task appears in a project once. Two rows would be two cards for one task in
             * one board, each with its own position.
             *
             * It is also the index for "where does this task appear" — the task detail
             * panel, every detach, and the cascade PostgreSQL runs when a task is deleted.
             * A separate `INDEX(task_id)` would be a prefix of this one: TASK-070-002
             * planned that lookup with and without it and got the same index scan at the
             * same cost, so the second index would have cost writes and bought nothing.
             */
            $table->unique(['task_id', 'project_id']);

            /*
             * Every read of a project's placements that does not name a section — the board
             * load, the list view, a detach-all. Without it that read is a sequential scan
             * (TASK-070-002): the two partial unique indexes below cannot serve it, because
             * a partial index only applies to a query that implies its predicate.
             */
            $table->index(['project_id', 'section_id', 'position']);
        });

        /*
         * The slot guard. It cannot be a plain `UNIQUE(project_id, section_id, position)`:
         * PostgreSQL treats NULLs as distinct in a unique index, so every placement in the
         * ungrouped bucket would be free to share a slot — and that bucket is exactly where
         * a list view drops cards. Two partial indexes cover both cases explicitly.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX task_project_memberships_slot_unique
            ON task_project_memberships (project_id, section_id, position)
            WHERE section_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX task_project_memberships_ungrouped_slot_unique
            ON task_project_memberships (project_id, position)
            WHERE section_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_project_memberships');
    }
};
