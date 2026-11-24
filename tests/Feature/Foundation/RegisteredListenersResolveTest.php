<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

test('every registered event listener class exists, resolves from the container and exposes its handler', function (): void {
    $problems = [];
    $checkedListeners = 0;

    foreach (Event::getRawListeners() as $eventName => $listeners) {
        if (str_contains((string) $eventName, '*')) {
            continue;
        }

        foreach ($listeners as $listener) {
            if ($listener instanceof Closure) {
                continue;
            }

            if (is_string($listener)) {
                [$class, $method] = Str::parseCallback($listener, 'handle');
            } elseif (is_array($listener) && is_string($listener[0] ?? null)) {
                [$class, $method] = [$listener[0], $listener[1] ?? 'handle'];
            } else {
                continue;
            }

            if (! class_exists($class)) {
                $problems[] = "{$eventName}: listener class {$class} does not exist";

                continue;
            }

            $checkedListeners++;

            try {
                $instance = app()->make($class);
            } catch (Throwable $exception) {
                $problems[] = "{$eventName}: {$class} cannot be resolved ({$exception->getMessage()})";

                continue;
            }

            if (! method_exists($instance, $method) && ! method_exists($instance, '__invoke')) {
                $problems[] = "{$eventName}: {$class} has no {$method}() handler";
            }
        }
    }

    expect($checkedListeners)->toBeGreaterThan(100)
        ->and($problems)->toBe([]);
});
