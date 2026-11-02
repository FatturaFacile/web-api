<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Replaces the commands that demo:reset chains with stand-ins that only record their arguments: the real ones would
 * drop the whole test database (shared with the other parallel test processes). The application is rebuilt for every
 * test, so the stand-ins never outlive the test that registers them.
 *
 * @param  array<string, int>  $exitCodes
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}>
 */
function fakeDemoResetSteps(array $exitCodes = []): ArrayObject
{
    $calls = new ArrayObject;
    $signatures = [
        'migrate:fresh' => 'migrate:fresh {--force}',
        'erp:bootstrap' => 'erp:bootstrap {--without-demo} {--force}',
        'db:seed' => 'db:seed {--class=} {--force}',
    ];

    foreach ($signatures as $name => $signature) {
        app(Kernel::class)->registerCommand(new class($signature, $name, $calls, $exitCodes[$name] ?? 0) extends Command
        {
            /** @param ArrayObject<int, array{0: string, 1: array<string, mixed>}> $calls */
            public function __construct(string $signature, private readonly string $stepName, private readonly ArrayObject $calls, private readonly int $exitCode)
            {
                $this->signature = $signature;
                parent::__construct();
            }

            public function handle(): int
            {
                $this->calls[] = [$this->stepName, array_filter($this->options(), fn (mixed $value): bool => $value !== null && $value !== false)];

                return $this->exitCode;
            }
        });
    }

    return $calls;
}

function demoResetEvent(): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'demo:reset'));
}

it('refuses to run unless the demo reset is enabled', function (): void {
    config(['app.demo_reset' => false]);
    $calls = fakeDemoResetSteps();
    $user = User::factory()->create();

    $this->artisan('demo:reset')->assertFailed();

    expect($calls)->toHaveCount(0)
        ->and(User::find($user->id))->not->toBeNull();
});

it('rebuilds the schema, then seeds reference data and the demo company', function (): void {
    config(['app.demo_reset' => true]);
    $calls = fakeDemoResetSteps();

    $this->artisan('demo:reset')->assertSuccessful();

    expect($calls->getArrayCopy())->toBe([
        ['migrate:fresh', ['force' => true]],
        ['erp:bootstrap', ['without-demo' => true, 'force' => true]],
        ['db:seed', ['class' => DemoSeeder::class, 'force' => true]],
    ]);
});

it('stops at the first step that fails', function (): void {
    config(['app.demo_reset' => true]);
    $calls = fakeDemoResetSteps(['erp:bootstrap' => 1]);

    $this->artisan('demo:reset')->assertFailed();

    expect(array_column($calls->getArrayCopy(), 0))->toBe(['migrate:fresh', 'erp:bootstrap']);
});

it('is scheduled every 30 minutes only when enabled', function (): void {
    $event = demoResetEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * *');

    config(['app.demo_reset' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['app.demo_reset' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});
