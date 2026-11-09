<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ambient_background', 16)->default('animated');
        });

        DB::table('users')->where('ambient_background_enabled', false)->update(['ambient_background' => 'off']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ambient_background_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ambient_background_enabled')->default(true);
        });

        DB::table('users')->where('ambient_background', 'off')->update(['ambient_background_enabled' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ambient_background');
        });
    }
};
