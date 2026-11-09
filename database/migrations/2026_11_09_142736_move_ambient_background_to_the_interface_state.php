<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The background is chosen in the user menu and kept with the rest of the interface (see User::interfaceState); what was
     * chosen in the profile is taken over.
     */
    public function up(): void
    {
        DB::table('users')->where('ambient_background', '!=', 'animated')->get(['id', 'interface', 'ambient_background'])->each(function (object $row): void {
            $state = $row->interface === null ? [] : json_decode($row->interface, true);
            $state['ambient'] = $row->ambient_background;
            $state['rev'] = ($state['rev'] ?? 0) + 1;

            DB::table('users')->where('id', $row->id)->update(['interface' => json_encode($state), 'interface_rev' => DB::raw('interface_rev + 1')]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ambient_background');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ambient_background', 16)->default('animated');
        });
    }
};
