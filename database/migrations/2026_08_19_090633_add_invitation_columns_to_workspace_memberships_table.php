<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('joined_at');

            $table->foreignId('invited_by')->nullable()->after('expires_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('expires_at');
        });
    }
};
