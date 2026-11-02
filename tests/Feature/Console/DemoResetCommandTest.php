<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// No RefreshDatabase (migrate:fresh cannot run inside its transaction) nor DatabaseMigrations (its rollback fails on
// SQLite): each test starts from a fresh schema of its own.
beforeEach(function (): void {
    $this->artisan('migrate:fresh');
});

function demoResetEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'demo:reset'));
}

it('refuses to run unless the demo reset is enabled', function (): void {
    config(['app.demo_reset' => false]);
    $user = User::factory()->create();

    $this->artisan('demo:reset')->assertFailed();

    expect(User::find($user->id))->not->toBeNull();
});

it('rebuilds the database with only the demo data', function (): void {
    config(['app.demo_reset' => true]);
    $visitor = User::factory()->create(['email' => 'visitor@example.com']);

    $this->artisan('demo:reset')->assertSuccessful();

    expect(User::where('email', $visitor->email)->exists())->toBeFalse()
        ->and(User::where('email', DemoSeeder::EMAIL)->exists())->toBeTrue()
        ->and(Contact::withoutGlobalScopes()->count())->toBeGreaterThan(0);
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
