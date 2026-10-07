<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<array{key: string, name: string, color: string, position: int, is_done: bool}>
     */
    private array $defaults = [
        ['key' => 'todo', 'name' => 'Offen', 'color' => 'zinc', 'position' => 0, 'is_done' => false],
        ['key' => 'in_progress', 'name' => 'In Arbeit', 'color' => 'blue', 'position' => 1, 'is_done' => false],
        ['key' => 'done', 'name' => 'Erledigt', 'color' => 'green', 'position' => 2, 'is_done' => true],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('zinc');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_done')->default(false);
            $table->timestamps();
        });

        foreach (DB::table('projects')->pluck('id') as $projectId) {
            foreach ($this->defaults as $default) {
                DB::table('task_statuses')->insert([
                    'project_id' => $projectId,
                    'name' => $default['name'],
                    'color' => $default['color'],
                    'position' => $default['position'],
                    'is_done' => $default['is_done'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('task_statuses')->restrictOnDelete();
        });

        foreach ($this->defaults as $default) {
            DB::table('tasks')->where('status', $default['key'])->update([
                'status_id' => DB::raw('(select id from task_statuses where task_statuses.project_id = tasks.project_id and task_statuses.position = '.$default['position'].')'),
            ]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status')->default('todo')->index()->after('status_id');
        });

        DB::table('tasks')->update([
            'status' => DB::raw("case when (select is_done from task_statuses where task_statuses.id = tasks.status_id) then 'done' else 'todo' end"),
        ]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::dropIfExists('task_statuses');
    }
};
