<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not index foreign keys: without this, deleting a rule (automation_id is set to null)
 * scans the whole history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->index('automation_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->dropIndex(['automation_id']);
        });
    }
};
