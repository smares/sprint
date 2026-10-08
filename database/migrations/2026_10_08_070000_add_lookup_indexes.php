<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not index foreign key columns by itself; the lists, "My tasks", the digest and
 * the visibility checks look tasks, comments and memberships up by exactly these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['project_id', 'parent_id', 'position']);
            $table->index('assignee_id');
            $table->index('status_id');
            $table->index('due_date');
        });

        Schema::table('comments', fn (Blueprint $table) => $table->index('task_id'));
        Schema::table('attachments', fn (Blueprint $table) => $table->index('task_id'));
        Schema::table('task_statuses', fn (Blueprint $table) => $table->index('project_id'));
        Schema::table('custom_fields', fn (Blueprint $table) => $table->index('project_id'));
        Schema::table('custom_field_options', fn (Blueprint $table) => $table->index('custom_field_id'));
        Schema::table('saved_filters', fn (Blueprint $table) => $table->index('project_id'));

        Schema::table('tag_task', fn (Blueprint $table) => $table->index('task_id'));
        Schema::table('task_collaborators', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('task_notification_mutes', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('project_members', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('team_user', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('project_team', fn (Blueprint $table) => $table->index('team_id'));
        Schema::table('task_dependencies', fn (Blueprint $table) => $table->index('blocked_id'));
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'parent_id', 'position']);
            $table->dropIndex(['assignee_id']);
            $table->dropIndex(['status_id']);
            $table->dropIndex(['due_date']);
        });

        Schema::table('comments', fn (Blueprint $table) => $table->dropIndex(['task_id']));
        Schema::table('attachments', fn (Blueprint $table) => $table->dropIndex(['task_id']));
        Schema::table('task_statuses', fn (Blueprint $table) => $table->dropIndex(['project_id']));
        Schema::table('custom_fields', fn (Blueprint $table) => $table->dropIndex(['project_id']));
        Schema::table('custom_field_options', fn (Blueprint $table) => $table->dropIndex(['custom_field_id']));
        Schema::table('saved_filters', fn (Blueprint $table) => $table->dropIndex(['project_id']));

        Schema::table('tag_task', fn (Blueprint $table) => $table->dropIndex(['task_id']));
        Schema::table('task_collaborators', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('task_notification_mutes', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('project_members', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('team_user', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('project_team', fn (Blueprint $table) => $table->dropIndex(['team_id']));
        Schema::table('task_dependencies', fn (Blueprint $table) => $table->dropIndex(['blocked_id']));
    }
};
