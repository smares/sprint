<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::login')->name('login')->middleware('guest');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::livewire('/tasks/{task}/notifications/{user}', 'pages::tasks.notifications')
    ->name('tasks.notifications')
    ->middleware('signed');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/projects');

    Route::livewire('/search', 'pages::search')->name('search');
    Route::livewire('/projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('/projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('/projects/{project}/fields', 'pages::projects.fields')->name('projects.fields');
    Route::livewire('/projects/{project}/members', 'pages::projects.members')->name('projects.members');
    Route::livewire('/projects/{project}/statuses', 'pages::projects.statuses')->name('projects.statuses');
    Route::livewire('/projects/{project}/board', 'pages::projects.board')->name('projects.board');
    Route::livewire('/projects/{project}/calendar', 'pages::projects.calendar')->name('projects.calendar');
    Route::livewire('/projects/{project}/timeline', 'pages::projects.timeline')->name('projects.timeline');
    Route::middleware('can:administer')->prefix('admin')->group(function () {
        Route::livewire('/users', 'pages::admin.users')->name('admin.users');
        Route::livewire('/teams', 'pages::admin.teams')->name('admin.teams');
    });

    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.show');

    Route::livewire('/tasks/mine', 'pages::tasks.mine')->name('tasks.mine');
    Route::livewire('/tasks/{task}', 'pages::tasks.show')->name('tasks.show');
});
