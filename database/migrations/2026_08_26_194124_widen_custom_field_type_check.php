<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\CustomFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `email`, `phone` and `link` join the field types.
 *
 * The constraint that names them is written from the enum, so a **fresh** database has already
 * had them since the moment the cases were added — this migration is for the databases that
 * already exist, whose constraint was written from the enum as it stood in Phase 150.
 *
 * Dropped and rebuilt rather than altered: PostgreSQL has no `ALTER CONSTRAINT` for a CHECK, and
 * rebuilding from the same source the original used is what keeps the two from drifting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(CustomFieldType::cases());
    }

    /**
     * Back to the five types Phase 150 defined. Any field of a type this removes would make the
     * constraint invalid, so they go first — a `down()` that leaves the table unable to satisfy
     * its own CHECK is a `down()` that does not run.
     */
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
