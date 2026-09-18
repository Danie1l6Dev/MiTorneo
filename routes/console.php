<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Only the public-demo account's data is reset (see DemoResetService).
Schedule::command('demo:reset')->everyThreeHours()->when(fn () => config('demo.enabled'));
