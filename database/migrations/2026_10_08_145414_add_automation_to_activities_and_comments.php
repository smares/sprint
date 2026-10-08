<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->foreignId('automation_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('automation_name')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('automation_name');
        });

        Schema::table('task_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_id');
        });
    }
};
