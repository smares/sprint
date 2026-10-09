<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ConfirmEmailController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProgressiveWebAppController;
use App\Http\Controllers\ProjectExportController;
use App\Services\LocaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

Route::post('/locale', function (Request $request): RedirectResponse {
    $locale = $request->validate(['locale' => ['required', Rule::in(LocaleService::codes())]])['locale'];

    $request->session()->put('locale', $locale);
    $request->user()?->update(['locale' => $locale]);

    return back();
})->name('locale.update');

Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::controller(ProgressiveWebAppController::class)->group(function () {
    Route::get('/manifest.webmanifest', 'manifest')->name('pwa.manifest');
    Route::get('/sw.js', 'serviceWorker')->name('pwa.service-worker');
    Route::get('/offline', 'offline')->name('offline');
});

Route::livewire('/login', 'pages::login')->name('login')->middleware('guest');
Route::livewire('/forgot-password', 'pages::forgot-password')->name('password.request')->middleware('guest');
Route::livewire('/reset-password/{token}', 'pages::reset-password')->name('password.reset')->middleware('guest');

Route::middleware(['guest', 'throttle:6,1'])->group(function () {
    Route::get('/passkeys/login/options', [PasskeyLoginController::class, 'index'])->name('passkey.login-options');
    Route::post('/passkeys/login', [PasskeyLoginController::class, 'store'])->name('passkey.login');
});

Route::middleware(['auth', 'password.confirm', 'throttle:6,1'])->group(function () {
    Route::get('/user/passkeys/options', [PasskeyRegistrationController::class, 'index'])->name('passkey.registration-options');
    Route::post('/user/passkeys', [PasskeyRegistrationController::class, 'store'])->name('passkey.store');
});

Route::get('/email/confirm/{user}/{hash}', ConfirmEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('email.confirm');

Route::post('/logout', function (Request $request) {
    // This browser's push subscription, if any (app.js): no notifications for the next person on a shared device
    if ($request->filled('push_endpoint')) {
        $request->user()->deletePushSubscription($request->string('push_endpoint')->toString());
    }

    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return to_route('login');
})->middleware('auth')->name('logout');

Route::livewire('/tasks/{task}/notifications/{user}', 'pages::tasks.notifications')
    ->name('tasks.notifications')
    ->middleware('signed');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/projects');

    Route::livewire('/profile', 'pages::profile')->name('profile');
    Route::livewire('/inbox', 'pages::inbox')->name('inbox');
    Route::livewire('/search', 'pages::search')->name('search');
    Route::livewire('/projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('/projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('/projects/{project}/fields', 'pages::projects.fields')->name('projects.fields');
    Route::livewire('/projects/{project}/members', 'pages::projects.members')->name('projects.members');
    Route::livewire('/projects/{project}/automations', 'pages::projects.automations')->name('projects.automations');
    Route::livewire('/projects/{project}/board', 'pages::projects.board')->name('projects.board');
    Route::livewire('/projects/{project}/calendar', 'pages::projects.calendar')->name('projects.calendar');
    Route::livewire('/projects/{project}/timeline', 'pages::projects.timeline')->name('projects.timeline');
    Route::middleware('can:administer')->prefix('admin')->group(function () {
        Route::livewire('/users', 'pages::admin.users')->name('admin.users');
        Route::livewire('/teams', 'pages::admin.teams')->name('admin.teams');
    });

    Route::get('/projects/{project}/export', ProjectExportController::class)->name('projects.export');
    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.show');
    Route::get('/avatars/{user}', AvatarController::class)->name('avatars.show');

    Route::livewire('/tasks/mine', 'pages::tasks.mine')->name('tasks.mine');
    Route::livewire('/tasks/{task}', 'pages::tasks.show')->name('tasks.show');
});
