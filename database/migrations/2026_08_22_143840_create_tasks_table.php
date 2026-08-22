<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * A task is owned by a workspace and placed by nothing (ADR-0003). There is no
             * `project_id` and no `section_id` here, and adding one later would create the
             * second source of truth that ADR rejects — placement lives in
             * `task_project_memberships`, which Phase 070 creates.
             */
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Nulled, not cascaded. Deleting a parent must not take its subtasks with it:
             * they are tasks in their own right, they may be assigned to other people, and
             * a cascade would delete work nobody asked to delete. They become root tasks.
             */
            $table->uuid('parent_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('priority')->default(TaskPriority::Medium->value);

            $table->timestamp('due_at')->nullable();

            /*
             * Completion is a column, never something inferred from where the task sits: a
             * "Done" section is a name somebody chose (ADR-0004).
             */
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A deleted task is recoverable, and its comments and attachments outlive it.
            $table->softDeletes();

            // My Tasks: one person's open work in one workspace, which is the query the
            // application runs most often after a project listing.
            $table->index(['workspace_id', 'assignee_id', 'completed_at']);

            /*
             * PostgreSQL does not index the referencing side of a foreign key, so without
             * these a parent deletion, an account deletion or a subtask read scans the
             * table (the same lesson the projects migration recorded).
             */
            $table->index('parent_id');
            $table->index('created_by');
            $table->index('completed_by');
        });

        /*
         * Added after the table exists: a self-reference declared inside `CREATE TABLE`
         * is emitted before the primary key it points at, and PostgreSQL refuses it.
         */
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('tasks')->nullOnDelete();
        });

        $values = implode(', ', array_map(
            fn (TaskPriority $case): string => "'".$case->value."'",
            TaskPriority::cases(),
        ));

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
