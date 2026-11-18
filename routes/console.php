<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apply webhook events that arrived before we knew their generation.
Schedule::command('pixelforge:process-webhooks')->everyMinute()->withoutOverlapping();
