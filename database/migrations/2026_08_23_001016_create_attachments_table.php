<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('file_id')->constrained()->cascadeOnDelete();

            $table->string('attachable_type');
            $table->uuid('attachable_id');

            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id', 'created_at']);

            $table->unique(['file_id', 'attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
