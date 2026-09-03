<?php

use App\Console\Commands\RunDueWatches;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Watches are woken hourly, but the command decides what to do: each watch
| carries its own interval, and the scheduler only gives it the opportunity to
| look.
|
| withoutOverlapping is essential here: a scan can run longer than the
| scheduler's interval, and two concurrent passes would double the outbound
| requests -- exactly what the crawl policy exists to prevent.
*/
Schedule::command(RunDueWatches::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
