<?php

use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DaemonController;
use App\Http\Controllers\Api\DatabaseController;
use App\Http\Controllers\Api\DatabaseUserController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\QueueWorkerController;
use App\Http\Controllers\Api\ScheduledJobController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\SiteCommandController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Every request acts as the API key's user, so the same policies as the panel decide what it reaches. The key's
| abilities only narrow that further.
*/

Route::prefix('v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('user', [UserController::class, 'show'])->name('user');

    Route::get('servers', [ServerController::class, 'index'])->middleware('abilities:servers:read')->name('servers.index');
    Route::post('servers', [ServerController::class, 'store'])->middleware('abilities:servers:write')->name('servers.store');
    Route::get('sites', [SiteController::class, 'all'])->middleware('abilities:sites:read')->name('sites.all');

    Route::prefix('servers/{server}')->name('servers.')->middleware('can:view,server')->scopeBindings()->group(function () {
        Route::middleware('abilities:servers:read')->group(function () {
            Route::get('/', [ServerController::class, 'show'])->name('show');
            Route::get('activity', [TaskController::class, 'index'])->name('activity.index');
            Route::get('activity/{task}', [TaskController::class, 'show'])->name('activity.show');
        });

        Route::middleware('abilities:servers:write')->group(function () {
            Route::delete('/', [ServerController::class, 'destroy'])->name('destroy');
            Route::post('reboot', [ServerController::class, 'reboot'])->name('reboot');
            Route::post('optimize', [ServerController::class, 'optimize'])->name('optimize');
        });

        Route::get('sites', [SiteController::class, 'index'])->middleware('abilities:sites:read')->name('sites.index');
        Route::post('sites', [SiteController::class, 'store'])->middleware('abilities:sites:write')->name('sites.store');

        Route::middleware('abilities:daemons:read')->group(function () {
            Route::get('daemons', [DaemonController::class, 'index'])->name('daemons.index');
            Route::get('scheduled-jobs', [ScheduledJobController::class, 'index'])->name('scheduled-jobs.index');
        });

        Route::middleware('abilities:daemons:write')->group(function () {
            Route::post('daemons', [DaemonController::class, 'store'])->name('daemons.store');
            Route::post('daemons/{daemon}/restart', [DaemonController::class, 'restart'])->name('daemons.restart');
            Route::delete('daemons/{daemon}', [DaemonController::class, 'destroy'])->name('daemons.destroy');
            Route::post('scheduled-jobs', [ScheduledJobController::class, 'store'])->name('scheduled-jobs.store');
            Route::delete('scheduled-jobs/{scheduledJob}', [ScheduledJobController::class, 'destroy'])->name('scheduled-jobs.destroy');
        });

        Route::middleware('abilities:databases:read')->group(function () {
            Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
            Route::get('database-users', [DatabaseUserController::class, 'index'])->name('database-users.index');
        });

        Route::middleware('abilities:databases:write')->group(function () {
            Route::post('databases', [DatabaseController::class, 'store'])->name('databases.store');
            Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
            Route::post('database-users', [DatabaseUserController::class, 'store'])->name('database-users.store');
            Route::patch('database-users/{databaseUser}', [DatabaseUserController::class, 'update'])->name('database-users.update');
            Route::delete('database-users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('database-users.destroy');
        });
    });

    // A site's members reach it without access to the rest of its server.
    Route::prefix('servers/{server}/sites/{site}')->name('sites.')->middleware('can:view,site')->scopeBindings()->group(function () {
        Route::get('/', [SiteController::class, 'show'])->middleware('abilities:sites:read')->name('show');

        Route::middleware('abilities:sites:write')->group(function () {
            Route::patch('/', [SiteController::class, 'update'])->name('update');
            Route::delete('/', [SiteController::class, 'destroy'])->name('destroy');
            Route::put('repository', [SiteController::class, 'repository'])->name('repository');
        });

        Route::middleware('abilities:deployments:read')->group(function () {
            Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments.index');
            Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
            Route::get('deploy-script', [DeploymentController::class, 'script'])->name('deploy-script.show');
        });

        Route::middleware('abilities:deployments:write')->group(function () {
            Route::post('deployments', [DeploymentController::class, 'store'])->name('deployments.store');
            Route::put('deploy-script', [DeploymentController::class, 'updateScript'])->name('deploy-script.update');
            Route::put('quick-deploy', [DeploymentController::class, 'quickDeploy'])->name('quick-deploy');
        });

        Route::get('environment', [EnvironmentController::class, 'show'])->middleware('abilities:environment:read')->name('environment.show');
        Route::put('environment', [EnvironmentController::class, 'update'])->middleware('abilities:environment:write')->name('environment.update');

        Route::get('certificate', [CertificateController::class, 'show'])->middleware('abilities:certificates:read')->name('certificate.show');

        Route::middleware('abilities:certificates:write')->group(function () {
            Route::post('certificate', [CertificateController::class, 'store'])->name('certificate.store');
            Route::delete('certificate', [CertificateController::class, 'destroy'])->name('certificate.destroy');
        });

        Route::middleware('abilities:commands:read')->group(function () {
            Route::get('commands', [SiteCommandController::class, 'index'])->name('commands.index');
            Route::get('commands/{command}', [SiteCommandController::class, 'show'])->name('commands.show');
        });

        Route::post('commands', [SiteCommandController::class, 'store'])->middleware('abilities:commands:write')->name('commands.store');

        Route::middleware('abilities:daemons:read')->group(function () {
            Route::get('workers', [QueueWorkerController::class, 'index'])->name('workers.index');
            Route::get('scheduler', [QueueWorkerController::class, 'scheduler'])->name('scheduler.show');
        });

        Route::middleware('abilities:daemons:write')->group(function () {
            Route::post('workers', [QueueWorkerController::class, 'store'])->name('workers.store');
            Route::post('workers/{daemon}/restart', [QueueWorkerController::class, 'restart'])->name('workers.restart');
            Route::delete('workers/{daemon}', [QueueWorkerController::class, 'destroy'])->name('workers.destroy');
            Route::put('scheduler', [QueueWorkerController::class, 'updateScheduler'])->name('scheduler.update');
        });
    });
});
