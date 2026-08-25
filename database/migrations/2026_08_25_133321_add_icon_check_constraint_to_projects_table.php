<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `projects.icon` became a closed library rather than free text, so it carries its
     * constraint here for the reason `color`, `default_view` and `visibility` already do:
     * a value outside the enum is accepted silently by the column and then throws inside
     * the cast on every request that reads the row. NULL stays legal — a project without
     * an icon renders the first letter of its name.
     *
     * Nothing has ever written the column: it was added with the table in Phase 020 and
     * no screen or Action set it until now, so there is no legacy value to migrate.
     */
    public function up(): void
    {
        $values = implode(', ', array_map(
            fn (ProjectIcon $case): string => "'".$case->value."'",
            ProjectIcon::cases(),
        ));

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_icon_check CHECK (icon IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_icon_check');
    }
};
