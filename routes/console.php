<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Process pending follow-ups every minute.
| Only dispatches follow-ups where scheduled_at <= now().
|
| Server cron entry (run once):
| * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

Schedule::command('followups:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('subscriptions:expire')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();
