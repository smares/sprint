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
        Schema::create('project_members', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('editor');
            $table->timestamps();

            $table->primary(['project_id', 'user_id']);
        });

        // Until now everybody could do everything: keep that for existing projects and people.
        foreach (DB::table('projects')->pluck('id') as $projectId) {
            foreach (DB::table('users')->pluck('id') as $userId) {
                DB::table('project_members')->insert([
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'role' => 'admin',
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
        Schema::dropIfExists('project_members');
    }
};
