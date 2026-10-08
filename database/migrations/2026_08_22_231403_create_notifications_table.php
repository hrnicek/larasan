<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Replaces Laravel's default notifications table with a workspace-scoped one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            $table->string('type');
            $table->json('data');

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);

            $table->index(['workspace_id', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
