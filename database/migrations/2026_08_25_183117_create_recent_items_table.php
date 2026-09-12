<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type');
            $table->uuid('subject_id');

            $table->unique(['user_id', 'subject_type', 'subject_id']);

            $table->timestamp('opened_at');

            $table->index(['user_id', 'workspace_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_items');
    }
};
