<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tag on a task.
 *
 * No `workspace_id`: both sides already have one, and scoping is by joining the aggregate
 * (ADR-0005). What the schema cannot express is that the two must be the **same** workspace —
 * a foreign key proves each row exists, never that they belong together — so that check lives
 * in the Action and is asserted there (TASK-140-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_tag', function (Blueprint $table): void {
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();

            // One tag on one task once: attaching twice is the same tag, not two of them.
            $table->primary(['task_id', 'tag_id']);

            /*
             * PostgreSQL does not index the referencing side of a foreign key, and the primary
             * key covers `task_id` only as its leading column — so deleting a tag would scan
             * this table without this.
             */
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tag');
    }
};
