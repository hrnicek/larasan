<?php

use App\Console\Commands\ExpireWorkspaceInvitations;
use App\Console\Commands\SweepRemovedFiles;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command(ExpireWorkspaceInvitations::class)->hourly();

Schedule::command(SweepRemovedFiles::class)->dailyAt('03:20');
