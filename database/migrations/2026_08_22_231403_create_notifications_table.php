<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's notification table, **replaced rather than extended**.
 *
 * The framework's default has no workspace: it assumes notifications belong to a person and
 * nothing else. The Inbox here is workspace-scoped and the shell's unread badge is a
 * per-workspace count (`docs/ui/inbox.md`), so a notification that could not say which
 * workspace it came from would have to be resolved by loading whatever it points at — per row,
 * per badge, on every page (`docs/architecture/database.md`).
 *
 * Publishing the default migration and adding a column afterwards would leave the framework's
 * version in the history as the shape this table once had, and a second migration to explain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Written out rather than `morphs()`, whose `(notifiable_type, notifiable_id)`
             * index is a prefix of the one below — paid for on every write and never chosen
             * (TASK-070-002). `notifiable_id` is an integer because the only thing notified is
             * an account.
             */
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            $table->string('type');
            $table->json('data');

            // Null until it is read. The Inbox's whole reason for existing is the set of rows
            // where this is still null.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The Inbox's read: one person's notifications, unread first.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);

            // The badge's read: the unread count for one person in one workspace, which is the
            // query that runs on every page of the application.
            $table->index(['workspace_id', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
