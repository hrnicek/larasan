<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_custom_field_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('custom_field_id')->constrained()->cascadeOnDelete();

            $table->string('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->foreignUuid('value_option_id')->nullable()
                ->constrained('custom_field_options')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['task_id', 'custom_field_id']);

            $table->index(['custom_field_id', 'value_text']);
            $table->index(['custom_field_id', 'value_number']);
            $table->index(['custom_field_id', 'value_date']);
            $table->index(['custom_field_id', 'value_option_id']);
        });

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
