<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project's own color (`#rrggbb`) and icon (one emoji or character). Both are optional: without them a project shows
     * a color of its own derived from its id and the first letter of its name.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('description');
            $table->string('icon', 32)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['color', 'icon']);
        });
    }
};
