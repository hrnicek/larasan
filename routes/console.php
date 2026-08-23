<?php

use App\Console\Commands\ExpireWorkspaceInvitations;
use App\Console\Commands\SweepRemovedFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command(ExpireWorkspaceInvitations::class)->hourly();

// Daily rather than hourly: the window is measured in days, so running it more often would only
// mean asking the same question again before anything can have changed.
Schedule::command(SweepRemovedFiles::class)->dailyAt('03:20');
