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
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX custom_fields_workspace_id_lower_name_unique ON custom_fields (workspace_id, lower(name))');

        $values = implode(', ', array_map(
            fn (CustomFieldType $case): string => "'".$case->value."'",
            CustomFieldType::cases(),
        ));

        DB::statement("ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_type_check CHECK (type IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
