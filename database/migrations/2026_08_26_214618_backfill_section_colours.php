<?php

declare(strict_types=1);

use App\Domain\Section\Data\CreateSectionData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sections')
            ->whereNull('color')
            ->update(['color' => CreateSectionData::DEFAULT_COLOR->value]);
    }

    public function down(): void {}
};
