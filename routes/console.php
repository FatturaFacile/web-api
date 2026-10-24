<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tenants:backup --all')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('subscriptions:process')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('ecommerce:sync-orders')->everyFifteenMinutes()->withoutOverlapping();
