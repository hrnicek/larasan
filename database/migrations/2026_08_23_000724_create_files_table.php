<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Stored per file, so changing the configured disk does not strand existing files.
            $table->string('disk');
            $table->string('path');

            // Display metadata only: the stored path is generated, never derived from the client filename.
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('extension', 32);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);

            $table->json('metadata');

            $table->timestamps();

            $table->softDeletes();

            $table->unique(['disk', 'path']);

            $table->index('workspace_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
