<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use App\Services\Backup\BackupArchiveEncryptor;
use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WithoutSeeding;

uses(WithoutSeeding::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('private');
    Storage::fake('local');
    config(['backup.encryption.enabled' => true]);
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'DR Srl', 'legal_name' => 'DR Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Dora', 'email' => 'dora@dr-test.example', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
});

function drBackup(Tenant $tenant): TenantBackup
{
    $service = app(TenantBackupService::class);

    return $service->generate($service->create($tenant))->refresh();
}

function drStored(TenantBackup $backup): string
{
    return (string) Storage::disk($backup->disk)->get($backup->storagePath());
}

test('archives are encrypted at rest, restore transparently and the download is a plain zip', function (): void {
    Storage::disk('local')->put("tenants/{$this->tenant->id}/attachments/a.txt", 'secret attachment');
    $backup = drBackup($this->tenant);

    expect($backup->isCompleted())->toBeTrue()
        ->and(str_starts_with(drStored($backup), BackupArchiveEncryptor::MAGIC))->toBeTrue()
        ->and(str_contains(drStored($backup), 'secret attachment'))->toBeFalse()
        ->and(str_contains(drStored($backup), $this->tenant->id))->toBeFalse();

    $summary = app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant);
    expect($summary['files'])->toBe(1);

    $download = $this->withHeader('X-Tenant-Id', $this->tenant->id)->get("/api/v1/system/backups/{$backup->id}/download")->assertOk();
    expect(str_starts_with($download->streamedContent(), 'PK'))->toBeTrue();
});

test('old unencrypted archives still restore', function (): void {
    config(['backup.encryption.enabled' => false]);
    $backup = drBackup($this->tenant);

    expect(str_starts_with(drStored($backup), 'PK'))->toBeTrue();

    config(['backup.encryption.enabled' => true]);
    expect(app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant)['safety_backup_id'])->not->toBeEmpty();
});

test('a tampered, truncated or wrongly keyed encrypted archive is refused', function (): void {
    $encryptor = app(BackupArchiveEncryptor::class);
    $plain = tempnam(sys_get_temp_dir(), 'dr-');
    file_put_contents($plain, random_bytes(2_500_000));
    $sealed = tempnam(sys_get_temp_dir(), 'dr-');
    $out = tempnam(sys_get_temp_dir(), 'dr-');
    $encryptor->encrypt($plain, $sealed);

    $encryptor->decrypt($sealed, $out);
    expect(file_get_contents($out))->toBe(file_get_contents($plain));

    $bytes = (string) file_get_contents($sealed);
    $flipped = $bytes;
    $flipped[100] = $flipped[100] ^ "\x01";
    file_put_contents($sealed, $flipped);
    expect(fn () => $encryptor->decrypt($sealed, $out))->toThrow(RuntimeException::class);

    // Dropping the last chunks is detected through the "last chunk" marker bound to every chunk.
    $firstChunk = 8 + 4 + 12 + 16 + 1_048_576;
    file_put_contents($sealed, substr($bytes, 0, $firstChunk));
    expect(fn () => $encryptor->decrypt($sealed, $out))->toThrow(RuntimeException::class);

    config(['backup.encryption.key' => 'another-secret']);
    $encryptor->encrypt($plain, $sealed);
    config(['backup.encryption.key' => 'a-third-secret', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(fn () => $encryptor->decrypt($sealed, $out))->toThrow(RuntimeException::class);

    config(['backup.encryption.previous_keys' => ['another-secret']]);
    $encryptor->decrypt($sealed, $out);
    expect(file_get_contents($out))->toBe(file_get_contents($plain));
});

test('the drill and the check are scheduled next to the daily backup', function (): void {
    $events = collect(app(Schedule::class)->events());
    $expression = fn (string $command): ?string => $events->first(fn ($e) => str_contains((string) $e->command, $command))?->expression;

    expect($expression('tenants:backup --all'))->toBe('0 2 * * *')
        ->and($expression('tenants:restore-drill'))->toBe('0 4 * * 0')
        ->and($expression('tenants:backup-check'))->toBe('0 * * * *');
});

test('the restore drill verifies the latest backup without touching live data', function (): void {
    $backup = drBackup($this->tenant);
    $before = TenantBackup::withoutGlobalScopes()->count();

    $this->artisan('tenants:restore-drill', ['--tenant' => $this->tenant->id])->assertSuccessful()->expectsOutputToContain("backup {$backup->id} verified");

    expect(TenantBackup::withoutGlobalScopes()->count())->toBe($before);
});

test('the restore drill fails and alerts when the archive is corrupted or missing', function (): void {
    $backup = drBackup($this->tenant);
    Storage::disk($backup->disk)->put($backup->storagePath(), BackupArchiveEncryptor::MAGIC.'garbage');
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('critical')->times(2);
    Log::shouldIgnoreMissing();

    $this->artisan('tenants:restore-drill', ['--tenant' => $this->tenant->id])->assertFailed();

    Storage::disk($backup->disk)->delete($backup->storagePath());
    $this->artisan('tenants:restore-drill', ['--tenant' => $this->tenant->id])->assertFailed();

    $this->artisan('tenants:restore-drill', ['--tenant' => (string) Str::uuid()])->assertFailed();
});

test('the drill without a backup has nothing to do', function (): void {
    $this->artisan('tenants:restore-drill')->assertSuccessful();
});

test('stale or failing backups raise an alert and healthy ones do not', function (): void {
    config(['backup.rpo_hours' => 24, 'app.demo_reset' => false]);
    $this->artisan('tenants:backup-check')->assertSuccessful();

    drBackup($this->tenant);
    $this->artisan('tenants:backup-check')->assertSuccessful();

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('critical')->once();
    Log::shouldIgnoreMissing();
    $this->travelTo(now()->addHours(30));
    $this->artisan('tenants:backup-check')->assertFailed();
});

test('the status endpoint exposes the RTO and the encryption flag', function (): void {
    config(['backup.rto_minutes' => 90]);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/system/backups/status')
        ->assertOk()
        ->assertJsonPath('data.rto_minutes', 90)
        ->assertJsonPath('data.encrypted', true);
});
