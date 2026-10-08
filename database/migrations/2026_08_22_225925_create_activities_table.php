<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type');
            $table->uuid('subject_id');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type');
            $table->json('properties');

            $table->timestamp('created_at')->nullable(false);

            $table->index(['subject_type', 'subject_id', 'created_at']);

            $table->index('actor_id');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
