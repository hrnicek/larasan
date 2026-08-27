<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectAccessLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a workspace member gets on this project without a `project_memberships` row.
     *
     * ADR-0006 gave `visibility` the read half of that answer and left the write half
     * unstated, which in practice meant "nothing" — a board the whole workspace could open
     * was one nobody but its explicit members could add a column to, and that included the
     * workspace owner. Visibility and default access are two settings in every tool this one
     * is measured against, so they are two columns here.
     *
     * `editor` is the default, which is where Asana, ClickUp and Jira's `Open` all start.
     * `owner` is deliberately outside the constraint: managing a project — renaming,
     * archiving, granting access — stays with the people who were named, never with everybody
     * who can see it.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('default_access_level')->default(ProjectAccessLevel::Editor->value);
        });

        $values = implode(', ', array_map(
            fn (ProjectAccessLevel $case): string => "'".$case->value."'",
            ProjectAccessLevel::grantableByDefault(),
        ));

        DB::statement(
            "ALTER TABLE projects ADD CONSTRAINT projects_default_access_level_check CHECK (default_access_level IN ({$values}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_default_access_level_check');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('default_access_level');
        });
    }
};
