<?php

use App\Servers\DatabaseRestoreUpload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('swage:refresh-certificates')->daily();
Schedule::command('swage:check-reboots')->hourly();
Schedule::call(fn () => DatabaseRestoreUpload::prune())->name('prune-database-restore-uploads')->daily();
