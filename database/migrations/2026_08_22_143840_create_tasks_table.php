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

            // Project placement lives in `task_project_memberships`, never on the task. See ADR-0003.
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->uuid('parent_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('priority')->default(TaskPriority::Medium->value);

            $table->timestamp('due_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->softDeletes();

            $table->index(['workspace_id', 'assignee_id', 'completed_at']);

            $table->index('parent_id');
            $table->index('created_by');
            $table->index('completed_by');
        });

        // PostgreSQL rejects a self-reference emitted inside CREATE TABLE before its primary key.
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
