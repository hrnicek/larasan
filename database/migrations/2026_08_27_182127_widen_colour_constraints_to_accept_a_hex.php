<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Hex colours must be lower case so one colour cannot be stored under two spellings. See ADR-0021.
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['projects', 'sections', 'tags', 'custom_field_options'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_color_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_color_check CHECK (
                color IS NULL OR color IN ({$this->names()}) OR color ~ '^#[0-9a-f]{6}$'
            )");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->where('color', 'like', '#%')->update(['color' => null]);

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_color_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_color_check CHECK (color IN ({$this->names()}))");
        }
    }

    private function names(): string
    {
        return implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));
    }
};
