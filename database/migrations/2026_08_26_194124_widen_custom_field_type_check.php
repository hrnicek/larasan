<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(CustomFieldType::cases());
    }

    public function down(): void
    {
        DB::table('custom_fields')
            ->whereIn('type', [
                CustomFieldType::Email->value,
                CustomFieldType::Phone->value,
                CustomFieldType::Link->value,
            ])
            ->delete();

        $this->rebuild([
            CustomFieldType::Text,
            CustomFieldType::Number,
            CustomFieldType::Date,
            CustomFieldType::Boolean,
            CustomFieldType::Select,
        ]);
    }

    /**
     * @param  list<CustomFieldType>  $types
     */
    private function rebuild(array $types): void
    {
        $values = implode(', ', array_map(
            fn (CustomFieldType $case): string => "'".$case->value."'",
            $types,
        ));

        DB::statement('ALTER TABLE custom_fields DROP CONSTRAINT IF EXISTS custom_fields_type_check');
        DB::statement("ALTER TABLE custom_fields ADD CONSTRAINT custom_fields_type_check CHECK (type IN ({$values}))");
    }
};
