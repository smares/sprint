<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The value a field trigger waits for (stored like the field's values: an option id, a number, a date or a text);
     * empty means any value. `trigger_value` holds the field.
     */
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->string('trigger_field_value', 500)->nullable()->after('trigger_value');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn('trigger_field_value');
        });
    }
};
