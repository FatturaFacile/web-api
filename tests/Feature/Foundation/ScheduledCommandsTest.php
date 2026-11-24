<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * @return Event The scheduled telescope:prune event.
 */
function telescopePruneEvent(): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'telescope:prune'));

    expect($event)->toBeInstanceOf(Event::class);

    return $event;
}

test('telescope prune is skipped while telescope is disabled', function (): void {
    config(['telescope.enabled' => false]);

    expect(telescopePruneEvent()->filtersPass(app()))->toBeFalse();
});

test('telescope prune is skipped when telescope is enabled but its table was never migrated', function (): void {
    config(['telescope.enabled' => true]);

    expect(telescopePruneEvent()->filtersPass(app()))->toBeFalse();
});

test('every scheduled artisan command is registered', function (): void {
    $registered = array_keys(Artisan::all());

    foreach (app(Schedule::class)->events() as $event) {
        if ($event->command === null || ! preg_match('/artisan[\'"]?\s+([\w:-]+)/', $event->command, $matches)) {
            continue;
        }

        expect($registered)->toContain($matches[1]);
    }
});
