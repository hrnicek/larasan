<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Foreign keys cannot enforce that the task and tag share a workspace; the Action does. See ADR-0005.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_tag', function (Blueprint $table): void {
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['task_id', 'tag_id']);

            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tag');
    }
};
