<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * Which workspace the user was last in. Nulled rather than cascaded when the
             * workspace goes: losing a workspace must not delete the account, and the
             * next request falls back to resolving a workspace from the memberships.
             */
            $table->foreignUuid('current_workspace_id')
                ->nullable()
                ->after('id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_workspace_id');
        });
    }
};
