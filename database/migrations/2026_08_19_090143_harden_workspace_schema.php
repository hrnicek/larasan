<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL does not index the referencing side of a foreign key.
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->index('owner_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('current_workspace_id');
        });

        // PostgreSQL json has no equality operator, so DISTINCT, GROUP BY and GIN indexes need jsonb.
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->jsonb('settings')->default('{}')->change();
        });

        DB::statement($this->checkConstraint('role', WorkspaceRole::cases()));
        DB::statement($this->checkConstraint('status', WorkspaceMembershipStatus::cases()));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workspace_memberships DROP CONSTRAINT workspace_memberships_role_check');
        DB::statement('ALTER TABLE workspace_memberships DROP CONSTRAINT workspace_memberships_status_check');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->json('settings')->default(null)->change();
            $table->dropIndex(['owner_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['current_workspace_id']);
        });
    }

    /**
     * @param  list<WorkspaceRole|WorkspaceMembershipStatus>  $cases
     */
    private function checkConstraint(string $column, array $cases): string
    {
        $values = implode(', ', array_map(
            fn (WorkspaceRole|WorkspaceMembershipStatus $case): string => "'".$case->value."'",
            $cases,
        ));

        return "ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_{$column}_check CHECK ({$column} IN ({$values}))";
    }
};
