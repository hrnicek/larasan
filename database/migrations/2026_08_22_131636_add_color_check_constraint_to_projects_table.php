<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `projects.color` is cast to an enum, and a value outside it is accepted silently by
     * the column and then throws inside the cast on every request that reads the row —
     * the same reason `default_view` and `visibility` carry their constraint here.
     * NULL stays legal: a project without an accent colour renders the neutral default.
     */
    public function up(): void
    {
        $values = implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_color_check CHECK (color IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_color_check');
    }
};
