<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
