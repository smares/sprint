<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What `asana:import` has already taken over: the Asana id of a project, task, comment, attachment or heading and the
     * id of what it became here. A second run skips these and only adds what is new.
     */
    public function up(): void
    {
        Schema::create('asana_imports', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('gid', 32);
            $table->unsignedBigInteger('model_id');
            $table->timestamps();

            $table->unique(['kind', 'gid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_imports');
    }
};
