<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subtasks are looked up by their parent alone (subtrees, deleting a task with its subtasks), and
 * the bell counts the unread notifications of one person on every page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->index('parent_id'));
        Schema::table('notifications', fn (Blueprint $table) => $table->index(['notifiable_type', 'notifiable_id', 'read_at']));
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['parent_id']));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(['notifiable_type', 'notifiable_id', 'read_at']));
    }
};
