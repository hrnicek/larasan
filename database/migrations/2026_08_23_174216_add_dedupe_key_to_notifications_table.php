<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->after('type');

            // Leads with dedupe_key so the planner never picks this index for the Inbox read.
            $table->unique(['dedupe_key', 'notifiable_type', 'notifiable_id'], 'notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_dedupe_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};
