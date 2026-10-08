<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Foreign keys cannot enforce that the project and field share a workspace; the Action does. See ADR-0005.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_custom_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('custom_field_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['project_id', 'custom_field_id']);

            $table->index('custom_field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_custom_fields');
    }
};
