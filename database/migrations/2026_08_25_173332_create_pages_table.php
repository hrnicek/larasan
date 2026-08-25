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
        Schema::create('pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * A page belongs to a project and proves its workspace by joining to one, the way
             * a section does. No denormalised `workspace_id`: there is a single owning
             * aggregate here, and a second copy of the tenant is a second thing that can be
             * wrong (`.ai/rules/app.md`).
             */
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            // A page inside a page. The reference is added below rather than here: the
            // primary key it points at is not in place until the create statement finishes.
            $table->uuid('parent_id')->nullable();

            $table->string('title');

            /*
             * The document itself, as ProseMirror's own JSON rather than as markup (ADR-0017).
             * `jsonb` and not `json`: the column is read far more often than it is written, and
             * only jsonb can be indexed if a later question needs it.
             */
            $table->jsonb('content');

            // The first words, kept beside the document so a list of pages does not have to
            // walk every one of them to draw a second line under each title.
            $table->string('excerpt', 300)->nullable();

            /** Sparse, with the gap ADR-0009 specifies, so inserting between two siblings writes one row. */
            $table->integer('position');

            /*
             * What the client last read. A save carries it back, and a save carrying an older
             * number is refused rather than allowed to overwrite somebody's paragraph — the
             * cheapest honest answer until pages are edited together (ADR-0017).
             */
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('pages', function (Blueprint $table): void {
            // Cascading, because deleting a document deletes what was written underneath it
            // rather than orphaning a subtree at the root.
            $table->foreign('parent_id')->references('id')->on('pages')->cascadeOnDelete();
        });

        /*
         * The ordered read and the collision guard in one, exactly as `sections` has it — with
         * the addition PostgreSQL needs to make it true at the root: by default two NULLs are
         * distinct, so without `NULLS NOT DISTINCT` every top-level page would sit outside the
         * constraint that is supposed to hold it. Requires PostgreSQL 15; the project runs 16.
         */
        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_sibling_position_unique UNIQUE NULLS NOT DISTINCT (project_id, parent_id, position)');

        // Soft deletes make the constraint above a promise about live rows only; the partial
        // index is what an ordered read of a subtree actually uses.
        DB::statement('CREATE INDEX pages_subtree_index ON pages (project_id, parent_id, position) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
