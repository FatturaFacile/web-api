<?php

declare(strict_types=1);

use App\Jobs\GenerateTenantBackupJob;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\Inventory\Jobs\RecalculateInventoryValuationJob;
use Modules\Manufacturing\Jobs\ProcessMrpRunJob;
use Modules\ServiceManagement\Jobs\PollSupportMailboxesJob;

/**
 * @return list<ScheduledEvent>
 */
function scheduledEvents(): array
{
    Artisan::all();

    return app(Schedule::class)->events();
}

/**
 * @return array<class-string, int>
 */
function longRunningJobTimeouts(): array
{
    return [
        GenerateTenantBackupJob::class => (new GenerateTenantBackupJob('backup-id'))->timeout,
        PollSupportMailboxesJob::class => (new PollSupportMailboxesJob('tenant-id'))->timeout,
        RecalculateInventoryValuationJob::class => (new RecalculateInventoryValuationJob('tenant-id'))->timeout,
        ProcessMrpRunJob::class => (new ProcessMrpRunJob('tenant-id', '2027-01-01', '2027-03-31'))->timeout,
    ];
}

test('every scheduled entry runs on one server only and never overlaps itself', function () {
    $events = scheduledEvents();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $label = $event->description ?? $event->command ?? 'unnamed';

        expect($event->onOneServer)->toBeTrue("{$label} is missing onOneServer()")
            ->and($event->withoutOverlapping)->toBeTrue("{$label} is missing withoutOverlapping()");
    }

    $commands = implode("\n", array_map(fn (ScheduledEvent $event): string => (string) $event->command, $events));
    expect($commands)->toContain('subscriptions:process')
        ->toContain('invoices:send-reminders')
        ->toContain('sdi:poll');
});

test('the cache stores used by the scheduler support atomic locks', function () {
    expect(Cache::store()->getStore())->toBeInstanceOf(LockProvider::class)
        ->and(Cache::store('database')->getStore())->toBeInstanceOf(LockProvider::class);
});

test('retry_after outlives the longest job timeout on every queue connection', function () {
    $longestTimeout = max(longRunningJobTimeouts());

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($longestTimeout);
    }
});

test('every heavy job declares its timeout and has no explicit timeout above the worker one', function () {
    foreach (longRunningJobTimeouts() as $job => $timeout) {
        expect($timeout)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(900, "{$job} outlives the worker --timeout");
    }
});

test('jobs that must not run twice at once are unique', function (string $job) {
    expect(is_subclass_of($job, ShouldBeUnique::class))->toBeTrue();
})->with([
    PollSupportMailboxesJob::class,
    RecalculateInventoryValuationJob::class,
    ProcessMrpRunJob::class,
]);

test('the queue workers of the deploy files stop jobs before retry_after hands them to another worker', function (string $composeFile) {
    $content = (string) file_get_contents(base_path($composeFile));

    expect(preg_match('/queue:work[^\n]*--timeout=(\d+)/', $content, $matches))->toBe(1, "{$composeFile} has no worker --timeout");

    $workerTimeout = (int) $matches[1];
    expect($workerTimeout)->toBeGreaterThanOrEqual(max(longRunningJobTimeouts()))
        ->and($workerTimeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
})->with(['deploy/dev/compose.yml', 'docker-compose.yml']);
