<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_custom_field_values', function (Blueprint $table): void {
            $table->index('value_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_custom_field_values', function (Blueprint $table): void {
            $table->dropIndex(['value_option_id']);
        });
    }
};
