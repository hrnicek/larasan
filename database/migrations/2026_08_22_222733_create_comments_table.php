<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * A **direct** `workspace_id`, unlike the placement tables, which scope by joining
             * the aggregate that owns them. A comment is polymorphic: there is no single
             * aggregate to join through, and every scoped read would otherwise need a union
             * over each commentable table. ADR-0005 permits the column exactly here, and
             * `docs/architecture/database.md` tabulates which form each table uses.
             */
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The morph columns written out rather than `uuidMorphs()`, which would add its
             * own `(commentable_type, commentable_id)` index — a prefix of the one below, and
             * therefore an index paid for on every write and never chosen (TASK-070-002 found
             * the same shape on the placement table).
             */
            $table->string('commentable_type');
            $table->uuid('commentable_id');

            /*
             * Nullable and nulled on delete. A comment outlives the account that wrote it, the
             * way `tasks.completed_by` does: deleting a person must not rewrite a conversation
             * other people took part in.
             */
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            // Set when a comment is edited, and read by the feed. A thread that silently
            // rewrites what somebody said is a thread nobody can rely on (TASK-110-006).
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            /*
             * Soft, because a deleted comment leaves a hole a thread has to explain: the feed
             * shows that something was removed rather than closing the gap and changing what
             * the conversation appears to say.
             */
            $table->softDeletes();

            // The feed's read, in the feed's order.
            $table->index(['commentable_type', 'commentable_id', 'created_at']);

            /*
             * PostgreSQL does not index the referencing side of a foreign key, so without this
             * an account deletion scans every comment — the same gap the Phase 020 review
             * found on `projects`.
             */
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
