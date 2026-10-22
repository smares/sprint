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
        // An account at a sign-in provider (Google) that belongs to a person. It is the provider's own id (`sub`) that identifies
        // it, not the email address, which can change.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->string('email');
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });

        // Settings of the installation that administrators change in the app (see App\Services\SsoSettings)
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('social_accounts');
    }
};
