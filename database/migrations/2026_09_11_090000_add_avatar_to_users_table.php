<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The preset bound is literal, so shipping more illustrations needs a new migration.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('avatar_preset')->nullable()->after('ui_theme');
            $table->string('avatar_path')->nullable()->after('avatar_preset');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_avatar_preset_known CHECK (avatar_preset BETWEEN 1 AND 26)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_avatar_one_source CHECK (avatar_preset IS NULL OR avatar_path IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_avatar_one_source');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_avatar_preset_known');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_preset', 'avatar_path']);
        });
    }
};
