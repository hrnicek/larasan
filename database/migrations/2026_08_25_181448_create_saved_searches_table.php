<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A search somebody wants back: a term, the kind it was narrowed to, and the filters that were
 * on when they kept it.
 *
 * Owned by one person in one workspace. Not shared, because a saved search is a bookmark rather
 * than a report, and a shared one whose filters name projects half the workspace cannot open is
 * a list of things they are told about and may not read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Both ends cascade: a saved search outliving its owner or its workspace is a row
            // nothing can ever open.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('term');

            // Null means all four kinds, exactly as the palette's absent chip does.
            $table->string('kind')->nullable();

            /*
             * The filters as the URL carries them — project, assignee, completion. A column per
             * filter would need a migration every time the search screen grows one, and these
             * are never queried by: they are replayed into a query string.
             */
            $table->json('filters');

            $table->timestamps();

            // One name per person per workspace: two chips reading "Overdue" are two chips
            // nobody can tell apart.
            $table->unique(['user_id', 'workspace_id', 'name']);

            // The chip row is read by owner and workspace, newest first.
            $table->index(['user_id', 'workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
