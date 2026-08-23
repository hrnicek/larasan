<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Found by the Phase 150 review (TASK-150-011).
 *
 * `value_option_id` references `custom_field_options` and is nulled when an option is deleted.
 * PostgreSQL does not index the referencing side of a foreign key, and the index this table
 * already had leads with `custom_field_id` — so deleting one choice from one list scanned every
 * answer in the installation to find the rows to null.
 */
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
