<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a file is attached to.
 *
 * Separate from `files` because the two answer different questions: a file is an object with a
 * location, and an attachment is a claim that some thing points at it. The split is what lets
 * one file hang from two places without being stored twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Cascades: an attachment without its file is a row pointing at nothing. The file
             * itself is soft-deleted first (TASK-120-007), so this only fires when a file is
             * removed for good.
             */
            $table->foreignUuid('file_id')->constrained()->cascadeOnDelete();

            // Written out rather than `uuidMorphs()`, whose own index would be a prefix of the
            // one below — paid for on every write and never chosen (TASK-070-002).
            $table->string('attachable_type');
            $table->uuid('attachable_id');

            $table->timestamps();

            // The read a task detail makes: this thing's attachments, oldest first.
            $table->index(['attachable_type', 'attachable_id', 'created_at']);

            /*
             * Attaching the same file to the same thing twice is one attachment. Without this
             * a double-submitted form would show the same document twice and removing it once
             * would leave the other behind.
             */
            $table->unique(['file_id', 'attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
