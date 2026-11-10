<?php

use App\Servers\DatabaseRestoreUpload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('swage:refresh-certificates')->daily();
Schedule::command('swage:check-reboots')->hourly();
Schedule::command('swage:record-metrics')->everyFiveMinutes();
Schedule::command('swage:auto-update')->dailyAt('03:00')->when(fn (): bool => config('swage.self_update.automatic'))->runInBackground()->withoutOverlapping(120);
Schedule::call(fn () => DatabaseNotification::where('created_at', '<', now()->subDays(90))->delete())->name('prune-notifications')->daily();
Schedule::call(fn () => DatabaseRestoreUpload::prune())->name('prune-database-restore-uploads')->daily();
