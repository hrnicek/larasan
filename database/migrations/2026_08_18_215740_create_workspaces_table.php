<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('UTC');
            $table->json('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
