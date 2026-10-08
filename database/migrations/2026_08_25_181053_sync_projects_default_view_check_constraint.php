<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectDefaultView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A CHECK built from enum cases is frozen when it runs, so each new case needs a migration like this.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_default_view_check');
        DB::statement($this->constraint(ProjectDefaultView::cases()));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_default_view_check');
        DB::statement($this->constraint([ProjectDefaultView::List, ProjectDefaultView::Board]));
    }

    /**
     * @param  list<ProjectDefaultView>  $cases
     */
    private function constraint(array $cases): string
    {
        $values = implode(', ', array_map(
            fn (ProjectDefaultView $case): string => "'".$case->value."'",
            $cases,
        ));

        return "ALTER TABLE projects ADD CONSTRAINT projects_default_view_check CHECK (default_view IN ({$values}))";
    }
};
