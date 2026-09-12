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

Schedule::command(SweepRemovedFiles::class)->dailyAt('03:20');
