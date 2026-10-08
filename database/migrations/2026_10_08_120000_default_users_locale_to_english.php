<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * English is the default language now; existing people keep the language they have.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 2)->default('en')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 2)->default('de')->change();
        });
    }
};
