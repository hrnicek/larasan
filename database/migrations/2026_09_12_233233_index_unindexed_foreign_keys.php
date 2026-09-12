<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $columns = [
        'tasks' => ['assignee_id'],
        'task_followers' => ['user_id'],
        'workspace_memberships' => ['invited_by'],
        'comments' => ['workspace_id'],
        'pages' => ['workspace_id', 'parent_id', 'created_by', 'updated_by'],
        'saved_searches' => ['workspace_id'],
        'recent_items' => ['workspace_id'],
    ];

    // PostgreSQL does not index the referencing side of a foreign key, and none of these columns leads an index.
    public function up(): void
    {
        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->index($column);
                }
            });
        }

        // Partial so the ungrouped bucket read keeps its own slot index; section_id = $1 still implies the predicate.
        DB::statement(<<<'SQL'
            CREATE INDEX task_project_memberships_section_id_position_index
            ON task_project_memberships (section_id, position)
            WHERE section_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX task_project_memberships_section_id_position_index');

        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    }
};
