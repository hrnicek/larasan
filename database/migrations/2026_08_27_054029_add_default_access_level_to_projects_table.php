<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectAccessLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // `owner` is never grantable by default; managing a project needs a membership. See ADR-0020.
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
