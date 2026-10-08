<?php

namespace App\Providers;

use App\Events\InboxUpdated;
use App\Models\User;
use App\Services\AutomationService;
use App\Services\LocaleService;
use App\Services\RealtimeService;
use App\Services\TaskSearchService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(RealtimeService::class);
        $this->app->singleton(AutomationService::class);

        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', (string) $proxies)));
        }

        LocaleService::useIsoDateFormats();

        Gate::define('administer', fn (User $user) => $user->is_admin);

        Event::listen(function (NotificationSent $sent) {
            if ($sent->channel === 'database' && $sent->notifiable instanceof User && $this->app->make(RealtimeService::class)->enabled()) {
                broadcast(new InboxUpdated($sent->notifiable->id));
            }
        });

        // The mail header (resources/views/vendor/mail/html/header.blade.php) shows the logo as cid:sprint-logo.png:
        // embedded in the mail, it shows without a public APP_URL and is not blocked like data: URLs are in Gmail and Outlook.
        Event::listen(function (MessageSending $sending) {
            if (str_contains((string) $sending->message->getHtmlBody(), 'cid:sprint-logo.png')) {
                $sending->message->embedFromPath(resource_path('images/mail-logo.png'), 'sprint-logo.png', 'image/png');
            }
        });

        Passkeys::authorizeLoginUsing(fn (Request $request, PasskeyUser $user) => $user instanceof User && $user->isActive());

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()));
    }
}
