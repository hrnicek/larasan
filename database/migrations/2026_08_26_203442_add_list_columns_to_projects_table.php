<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a project draws its list columns in.
 *
 * JSON, and this is the one place in this schema where that is the right answer. The typed columns
 * on `task_custom_field_values` are typed because they are sorted and filtered by; this is read
 * whole, written whole, and never appears in a `WHERE` or an `ORDER BY` — indexing it would index
 * nothing anybody asks for.
 *
 * Nullable, and null is not "no columns": it is a project nobody has reordered, which draws the
 * order it always drew — its fields by `project_custom_fields.position`, then Assignee, Due,
 * Priority. That is what keeps this migration from changing a single existing screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('list_columns')->nullable()->after('default_view');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('list_columns');
        });
    }
};
