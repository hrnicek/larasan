<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the workspace's fields a project actually shows.
 *
 * A workspace can define more than any one project wants on its screen, so attaching is a
 * decision per project rather than a consequence of defining.
 *
 * No `workspace_id`: reached through the project (ADR-0005). That both sides must belong to the
 * same workspace is the Action's check, exactly as it is for tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_custom_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('custom_field_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            // One field on one project once: attaching twice is the same column, not two.
            $table->unique(['project_id', 'custom_field_id']);

            // PostgreSQL does not index the referencing side of a foreign key, and the unique
            // index leads with `project_id` — so removing a field would scan this table.
            $table->index('custom_field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_custom_fields');
    }
};
