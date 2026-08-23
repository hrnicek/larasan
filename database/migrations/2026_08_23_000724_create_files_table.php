<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an object is, and what it was called.
 *
 * `disk` is stored beside `path` on purpose (ADR-0007): the disk a file was written to is a
 * fact about that file, not about today's configuration, so changing
 * `FILESYSTEM_ATTACHMENTS_DISK` moves new uploads without stranding old ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Direct, as a comment's is: a file hangs from anything through `attachments`, so
            // there is no single aggregate to join through (ADR-0005).
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Nulled on delete, for the reason a comment's author is: a file other people are
             * still working with must not disappear because the person who uploaded it left.
             */
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('disk');
            $table->string('path');

            // Metadata for display and for the download header. The stored path is generated
            // and never derived from this (ADR-0007), so a hostile filename is a string here
            // and nothing more.
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('extension', 32);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);

            $table->json('metadata');

            $table->timestamps();

            // Soft, so a row can stop being reachable before the object behind it is removed:
            // deleting bytes inside a request is the one part of this that cannot be undone.
            $table->softDeletes();

            /*
             * One row per object. Two rows pointing at one path would make deleting either of
             * them delete the other's bytes, which is the kind of bug that is only found by
             * losing somebody's file.
             */
            $table->unique(['disk', 'path']);

            // PostgreSQL does not index the referencing side of a foreign key, so without these
            // a workspace or account deletion scans the table.
            $table->index('workspace_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
