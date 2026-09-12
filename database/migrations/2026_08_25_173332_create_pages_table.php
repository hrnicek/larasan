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

            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            // Constrained below, once the primary key it references exists.
            $table->uuid('parent_id')->nullable();

            $table->string('title');

            $table->jsonb('content');

            $table->string('excerpt', 300)->nullable();

            $table->integer('position');

            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('pages')->cascadeOnDelete();
        });

        // NULLS NOT DISTINCT (PostgreSQL 15+) so root pages, whose parent_id is null, are constrained too.
        DB::statement('ALTER TABLE pages ADD CONSTRAINT pages_sibling_position_unique UNIQUE NULLS NOT DISTINCT (project_id, parent_id, position)');

        DB::statement('CREATE INDEX pages_subtree_index ON pages (project_id, parent_id, position) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
