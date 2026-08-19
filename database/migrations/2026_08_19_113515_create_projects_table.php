<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();

            /*
             * Both nullable and nulled on delete. A project outlives the person who made
             * it: cascading would destroy a team's work because one account closed, and
             * restricting would block that account's deletion with no transfer flow to
             * unblock it — the workspace already restricts for the one case where
             * ownership must be handed over deliberately. A project with no owner is still
             * administered through the workspace capability.
             */
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('default_view')->default(ProjectDefaultView::List->value);
            $table->string('visibility')->default(ProjectVisibility::Workspace->value);

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Per workspace, not global: two tenants may both have a project called "Web".
            $table->unique(['workspace_id', 'slug']);

            // What the project list filters on.
            $table->index(['workspace_id', 'archived_at']);

            /*
             * PostgreSQL does not index the referencing side of a foreign key, so without
             * these an account deletion scans every project (Phase 020 database review).
             */
            $table->index('owner_id');
            $table->index('created_by');
        });

        /*
         * Enum columns carry their constraint in the database, as workspace_memberships
         * does: a value outside the enum is accepted silently and then fails at read time
         * inside the cast, on every request that touches the row.
         */
        DB::statement($this->checkConstraint('default_view', ProjectDefaultView::cases()));
        DB::statement($this->checkConstraint('visibility', ProjectVisibility::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }

    /**
     * @param  list<ProjectDefaultView|ProjectVisibility>  $cases
     */
    private function checkConstraint(string $column, array $cases): string
    {
        $values = implode(', ', array_map(
            fn (ProjectDefaultView|ProjectVisibility $case): string => "'".$case->value."'",
            $cases,
        ));

        return "ALTER TABLE projects ADD CONSTRAINT projects_{$column}_check CHECK ({$column} IN ({$values}))";
    }
};
