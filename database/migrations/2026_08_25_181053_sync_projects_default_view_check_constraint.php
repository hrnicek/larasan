<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectDefaultView;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `projects_default_view_check` was written from `ProjectDefaultView::cases()` on the day the
 * table was created, which froze the enum as it stood then. `Calendar` was added to the enum
 * afterwards without one of these, so every database migrated before that day still refuses
 * the value the settings form offers — the constraint and the enum agree only on a database
 * built from scratch, and CI is always built from scratch.
 *
 * Rebuilt rather than patched: reading the enum again is what keeps the two in step, and the
 * next case added still needs its own migration for the same reason this one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_default_view_check');
        DB::statement($this->constraint(ProjectDefaultView::cases()));
    }

    /**
     * The two views the constraint allowed before `Calendar` existed. A project already saved
     * as `calendar` would refuse to go back through it, which is the honest answer: rolling
     * this back is rolling back a value the application can now hold.
     */
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
