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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('repeat_unit')->nullable()->after('due_date');
            $table->unsignedSmallInteger('repeat_interval')->default(1)->after('repeat_unit');
            $table->string('repeat_mode')->default('schedule')->after('repeat_interval');
            $table->date('repeat_until')->nullable()->after('repeat_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['repeat_unit', 'repeat_interval', 'repeat_mode', 'repeat_until']);
        });
    }
};
