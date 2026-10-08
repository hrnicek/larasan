<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['sections', 'task_project_memberships', 'pages', 'attachments'];

    // Appends add SparsePosition::GAP with no ceiling, which exhausts a 32-bit column after 32,767 appends.
    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->bigInteger('position')->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->integer('position')->change();
            });
        }
    }
};
