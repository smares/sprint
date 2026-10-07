<?php

namespace App\Providers;

use App\Models\User;
use App\TaskSearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskSearch::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administer', fn (User $user) => $user->is_admin);

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()));
    }
}
