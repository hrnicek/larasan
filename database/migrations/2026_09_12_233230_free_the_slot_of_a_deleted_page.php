<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pages DROP CONSTRAINT pages_sibling_position_unique');

        DB::statement('DROP INDEX pages_subtree_index');

        // Positions are computed from live siblings, so a soft-deleted page must not keep holding its slot.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pages_sibling_position_unique
            ON pages (project_id, parent_id, position) NULLS NOT DISTINCT
            WHERE deleted_at IS NULL
        SQL);

        // The partial index cannot serve the project's cascade, which must also reach deleted pages.
        DB::statement('CREATE INDEX pages_project_id_index ON pages (project_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX pages_project_id_index');

        DB::statement('DROP INDEX pages_sibling_position_unique');

        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_sibling_position_unique UNIQUE NULLS NOT DISTINCT (project_id, parent_id, position)');

        DB::statement('CREATE INDEX pages_subtree_index ON pages (project_id, parent_id, position) WHERE deleted_at IS NULL');
    }
};
