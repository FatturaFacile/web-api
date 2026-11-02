<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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

it('rebuilds the schema, then runs the full bootstrap with the demo tenant', function (): void {
    config(['app.demo_reset' => true]);
    $calls = fakeDemoResetSteps();

    $this->artisan('demo:reset')->assertSuccessful();

    expect($calls->getArrayCopy())->toBe([
        ['migrate:fresh', ['force' => true]],
        ['erp:bootstrap', ['force' => true]],
    ]);
});

it('empties the storage disks but keeps .gitignore', function (): void {
    config([
        'app.demo_reset' => true,
        'filesystems.private_disk' => 'local',
        'backup.disk' => 'private',
        'backup.files_disk' => 'local',
    ]);
    fakeDemoResetSteps();
    foreach (['local', 'private', 'public'] as $disk) {
        Storage::fake($disk);
        Storage::disk($disk)->put('.gitignore', "*\n");
        Storage::disk($disk)->put('tenants/abc/receipt.pdf', 'pdf');
        Storage::disk($disk)->put('signature.png', 'png');
    }

    $this->artisan('demo:reset')->assertSuccessful();

    foreach (['local', 'private', 'public'] as $disk) {
        expect(Storage::disk($disk)->allFiles())->toBe(['.gitignore'])
            ->and(Storage::disk($disk)->directories())->toBe([]);
    }
});

it('leaves the storage alone when disabled', function (): void {
    config(['app.demo_reset' => false]);
    Storage::fake('public');
    Storage::disk('public')->put('logo.png', 'png');

    $this->artisan('demo:reset')->assertFailed();

    expect(Storage::disk('public')->exists('logo.png'))->toBeTrue();
});

it('stops at the first step that fails', function (): void {
    config(['app.demo_reset' => true]);
    $calls = fakeDemoResetSteps(['migrate:fresh' => 1]);

    $this->artisan('demo:reset')->assertFailed();

    expect(array_column($calls->getArrayCopy(), 0))->toBe(['migrate:fresh']);
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
