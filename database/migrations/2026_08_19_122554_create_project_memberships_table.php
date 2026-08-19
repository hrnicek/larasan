<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectAccessLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * No database default. Access level is an authorization decision, and a
             * default would make it silently — the same reason workspace_memberships.role
             * has none (ADR-0006, ADR-0010).
             */
            $table->string('access_level');

            $table->timestamps();

            $table->unique(['project_id', 'user_id']);

            /*
             * "Which projects may this person see" runs on every project listing, and
             * PostgreSQL does not index the referencing side of a foreign key. The unique
             * index already covers lookups that start from the project.
             */
            $table->index('user_id');
        });

        DB::statement($this->checkConstraint());
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
    }

    private function checkConstraint(): string
    {
        $values = implode(', ', array_map(
            fn (ProjectAccessLevel $case): string => "'".$case->value."'",
            ProjectAccessLevel::cases(),
        ));

        return "ALTER TABLE project_memberships ADD CONSTRAINT project_memberships_access_level_check CHECK (access_level IN ({$values}))";
    }
};
