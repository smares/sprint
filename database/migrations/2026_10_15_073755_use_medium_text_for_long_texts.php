<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descriptions and comments may have up to 65,000 characters (Task::MAX_DESCRIPTION_LENGTH, Comment::MAX_BODY_LENGTH).
     * MySQL's TEXT holds only 65,535 bytes, which umlauts, Asian scripts and emoji exceed long before that; MEDIUMTEXT holds
     * 16 MB. SQLite and PostgreSQL have no such limit for text.
     */
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('tasks', fn (Blueprint $table) => $table->mediumText('description')->nullable()->change());
        Schema::table('comments', fn (Blueprint $table) => $table->mediumText('body')->change());
    }

    /**
     * Narrowing the columns again would cut long texts, so they stay as they are.
     */
    public function down(): void {}
};
