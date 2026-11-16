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

// Mantiene snella la tabella telescope_entries eliminando i dati oltre le 24 ore
Schedule::command('telescope:prune --hours=24')->dailyAt('03:00')->withoutOverlapping();

// Demo pubblica: database ricostruito da zero e storage svuotato ogni 30 minuti (solo con DEMO_RESET_ENABLED=true)
Schedule::command('demo:reset')->everyThirtyMinutes()->withoutOverlapping()->when(fn (): bool => (bool) config('app.demo_reset'));

// Module 17 ESM (GH-396): alert once per loaner unit still out after the repaired equipment was returned
Schedule::command('esm:loaners:scan-overdue')->dailyAt('07:30')->withoutOverlapping();
