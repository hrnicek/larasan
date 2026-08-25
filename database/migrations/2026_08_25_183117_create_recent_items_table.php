<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody had open lately, per workspace.
 *
 * One row per thing rather than a log: the palette's question is "what was I in", not "how often
 * was I in it", and a history table would grow without a reader. Opening the same task twice
 * moves its row rather than adding one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * A direct `workspace_id`, as `comments` has and for the same reason: the subject is
             * polymorphic, so there is no single aggregate to join through and a scoped read
             * would otherwise be a union over every subject's table (ADR-0005).
             */
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The morph columns written out rather than `uuidMorphs()`, which would add a
             * `(subject_type, subject_id)` index that is a prefix of the unique below and would
             * therefore be paid for on every write and never chosen.
             */
            $table->string('subject_type');
            $table->uuid('subject_id');

            // Opening the same thing twice is one row moved, not two rows kept.
            $table->unique(['user_id', 'subject_type', 'subject_id']);

            $table->timestamp('opened_at');

            // How the palette reads it: this person, this workspace, most recent first.
            $table->index(['user_id', 'workspace_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_items');
    }
};
