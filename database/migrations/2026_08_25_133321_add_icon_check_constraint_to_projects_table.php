<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
