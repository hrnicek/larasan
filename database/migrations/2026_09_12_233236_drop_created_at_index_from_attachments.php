<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A subject's attachments are read in position order, which the (attachable_type, attachable_id, position) unique index serves.
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['attachable_type', 'attachable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->index(['attachable_type', 'attachable_id', 'created_at']);
        });
    }
};
