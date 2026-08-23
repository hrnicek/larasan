<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One value per field per task, in typed columns.
 *
 * Typed rather than JSON because sorting and filtering by a field is the entire point of having
 * one (`docs/architecture/database.md`): a number has to sort numerically and a date
 * chronologically, and neither does inside a json document without a functional index per field.
 *
 * The CHECK is the other half of that decision: a row with two answers is a row nobody can read,
 * and the type on the field says which single column is the right one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_custom_field_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('custom_field_id')->constrained()->cascadeOnDelete();

            $table->string('value_text')->nullable();
            // Wide and exact: money and estimates both end up here, and a float would make
            // "1.1 + 2.2" a support ticket.
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();

            /*
             * Nulled when the option is deleted rather than cascading the row away: removing a
             * choice from a list must not silently delete what people had already answered, and
             * a value pointing at nothing is worse than an empty one.
             */
            $table->foreignUuid('value_option_id')->nullable()
                ->constrained('custom_field_options')
                ->nullOnDelete();

            $table->timestamps();

            // One answer per field per task. A second row would make "the value" a question with
            // two answers and no way to choose.
            $table->unique(['task_id', 'custom_field_id']);

            /*
             * The reads this table exists for: every task's value for one field, filtered or
             * sorted. Leading with the field is what makes a filter an index scan rather than a
             * scan of every value in the workspace (TASK-150-009).
             */
            $table->index(['custom_field_id', 'value_text']);
            $table->index(['custom_field_id', 'value_number']);
            $table->index(['custom_field_id', 'value_date']);
            $table->index(['custom_field_id', 'value_option_id']);
        });

        /*
         * At most one answer. Written from the enum so adding a type cannot leave the constraint
         * behind — the column list and the constraint come from the same place.
         */
        $columns = implode(' + ', array_map(
            fn (string $column): string => "(case when {$column} is null then 0 else 1 end)",
            CustomFieldType::columns(),
        ));

        DB::statement("ALTER TABLE task_custom_field_values ADD CONSTRAINT task_custom_field_values_one_value_check CHECK ({$columns} <= 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_custom_field_values');
    }
};
