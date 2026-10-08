<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_project_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('section_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('position');

            $table->timestamps();

            $table->unique(['task_id', 'project_id']);

            $table->index(['project_id', 'section_id', 'position']);
        });

        // Two partial indexes, because a unique index treats NULL section_ids as distinct.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX task_project_memberships_slot_unique
            ON task_project_memberships (project_id, section_id, position)
            WHERE section_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX task_project_memberships_ungrouped_slot_unique
            ON task_project_memberships (project_id, position)
            WHERE section_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_project_memberships');
    }
};
