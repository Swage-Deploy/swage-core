<?php

use App\Http\Controllers\Auth\SourceControlController;
use App\Http\Controllers\DatabaseExportController;
use App\Http\Controllers\DeployTriggerController;
use App\Http\Controllers\ProvisionController;
use App\Http\Controllers\SourceControlWebhookController;
use App\Http\Controllers\StopImpersonatingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('register', 'pages::auth.register')->middleware('guest')->name('register');

Route::post('impersonation/stop', StopImpersonatingController::class)->middleware('auth')->name('impersonation.stop');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::servers.index')->name('dashboard');
    Route::livewire('servers/create', 'pages::servers.create')->name('servers.create');
    Route::livewire('updates', 'pages::updates')->middleware('can:administer')->name('updates');

    // These change what Swage runs and the credentials it holds, so they ask for the password again.
    Route::livewire('users', 'pages::users.index')->middleware(['can:administer', 'password.confirm'])->name('users');
    Route::livewire('plugins/{category?}', 'pages::plugins.index')->middleware(['can:administer', 'password.confirm'])->where('category', 'system|server|site|activity')->name('plugins');
    Route::livewire('plugins/system/source-control/{provider}', 'pages::source-control.configure')->middleware(['can:administer', 'password.confirm'])->where('provider', '[a-z0-9-]+')->name('source-control.configure');

    Route::prefix('servers/{server}')->name('servers.')->middleware('can:view,server')->scopeBindings()->group(function () {
        Route::livewire('/', 'pages::servers.show')->name('show');
        Route::livewire('sites', 'pages::servers.sites')->name('sites');
        Route::livewire('databases', 'pages::servers.databases')->name('databases');
        Route::get('databases/{database}/export', DatabaseExportController::class)->middleware('can:transferDatabases,server')->name('databases.export');
        Route::livewire('daemons', 'pages::servers.daemons')->name('daemons');
        Route::livewire('scheduler', 'pages::servers.scheduler')->name('scheduler');
        Route::livewire('config', 'pages::servers.config')->name('config');
        Route::livewire('activity', 'pages::servers.activity')->name('activity');
        Route::livewire('logs', 'pages::servers.logs')->name('logs');
        Route::livewire('plugins', 'pages::servers.plugins')->name('plugins');
        Route::livewire('members', 'pages::servers.members')->name('members');
    });

    // A site's members reach its pages without access to the rest of its server.
    Route::prefix('servers/{server}/sites/{site}')->name('servers.sites.')->middleware('can:view,site')->scopeBindings()->group(function () {
        Route::livewire('/', 'pages::sites.show')->name('show');
        Route::livewire('ssl', 'pages::sites.ssl')->name('ssl');
        Route::livewire('queues', 'pages::sites.queues')->name('queues');
        Route::livewire('nginx', 'pages::sites.nginx')->name('nginx');
        Route::livewire('php', 'pages::sites.php')->name('php');
        Route::livewire('logs', 'pages::sites.logs')->name('logs');
        Route::livewire('commands', 'pages::sites.commands')->name('commands');
        Route::livewire('plugins', 'pages::sites.plugins')->name('plugins');
        Route::livewire('members', 'pages::sites.members')->name('members');
        Route::livewire('settings', 'pages::sites.settings')->name('settings');
    });

    Route::get('auth/{provider}/redirect', [SourceControlController::class, 'redirect'])->where('provider', '[a-z0-9-]+')->name('source-control.redirect');
    Route::get('auth/{provider}/callback', [SourceControlController::class, 'callback'])->where('provider', '[a-z0-9-]+')->name('source-control.callback');
});

Route::prefix('provision/{token}')->name('provision.')->middleware('throttle:120,1')->controller(ProvisionController::class)->group(function () {
    Route::get('/', 'script')->name('script');
    Route::post('specs', 'specs')->name('specs');
    Route::post('progress', 'progress')->name('progress');
    Route::post('finished', 'finished')->name('finished');
    Route::post('failed', 'failed')->name('failed');
});

Route::post('deploy/{site}/{token?}', DeployTriggerController::class)->middleware('throttle:30,1')->name('sites.deploy-trigger');
Route::post('webhooks/{provider}/{site}', SourceControlWebhookController::class)->where('provider', '[a-z0-9-]+')->middleware('throttle:60,1')->name('webhooks.source-control');

require __DIR__.'/settings.php';
