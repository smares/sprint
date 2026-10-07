<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('show_in_list')->default(true);
            $table->timestamps();
        });

        Schema::create('custom_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('zinc');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('custom_field_options')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'custom_field_id']);
        });

        // Every existing project gets the same "Priorität" field that new projects start with.
        foreach (DB::table('projects')->pluck('id') as $projectId) {
            $fieldId = DB::table('custom_fields')->insertGetId([
                'project_id' => $projectId,
                'name' => 'Priorität',
                'type' => 'select',
                'position' => 0,
                'show_in_list' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([['Niedrig', 'sky'], ['Mittel', 'amber'], ['Hoch', 'orange'], ['Dringend', 'red']] as $position => [$name, $color]) {
                DB::table('custom_field_options')->insert([
                    'custom_field_id' => $fieldId,
                    'name' => $name,
                    'color' => $color,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_options');
        Schema::dropIfExists('custom_fields');
    }
};
