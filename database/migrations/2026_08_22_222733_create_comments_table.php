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

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('commentable_type');
            $table->uuid('commentable_id');

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'created_at']);

            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
