<?php

namespace App\Providers;

use App\Models\User;
use App\Services\TaskSearchService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskSearchService::class);

        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::define('administer', fn (User $user) => $user->is_admin);

        Passkeys::authorizeLoginUsing(fn (Request $request, PasskeyUser $user) => $user instanceof User && $user->isActive());

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()));
    }
}
