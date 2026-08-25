<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pages` was created without a `workspace_id` on the argument that it has one owning
     * aggregate and can prove its tenant by joining to it — which is true, and is how `sections`
     * works.
     *
     * The search index is what changes the answer (ADR-0016). A searchable model needs the
     * tenant as an attribute of its own document, because the engine filters on attributes and
     * has no joins; and the suite runs Scout's `collection` engine, which resolves those same
     * attributes against the table. Every other searchable model here — tasks, projects,
     * comments — carries the column for exactly that reason.
     *
     * It is derived and never moves: a project does not change workspace, so neither does a page.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE pages SET workspace_id = projects.workspace_id FROM projects WHERE projects.id = pages.project_id');

        Schema::table('pages', function (Blueprint $table): void {
            $table->uuid('workspace_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
