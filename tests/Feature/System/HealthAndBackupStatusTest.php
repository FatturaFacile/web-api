<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function healthMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@health-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function healthBackup(Tenant $tenant, string $status, string $when): TenantBackup
{
    return TenantBackup::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'filename' => Str::uuid().'.zip',
        'disk' => 'private',
        'file_size_bytes' => 1024,
        'status' => $status,
        'error_message' => $status === TenantBackup::FAILED ? 'disk full' : null,
        'completed_at' => $status === TenantBackup::COMPLETED ? $when : null,
        'created_at' => $when,
        'updated_at' => $when,
    ]);
}

test('the readiness probe is public and reports every check', function (): void {
    $this->getJson('/api/v1/health/ready')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok', 'storage' => 'ok']]);
});

test('the backup status is never, stale, ok or failing and stays inside the tenant', function (): void {
    $this->travelTo('2027-03-10 12:00:00');
    config(['backup.rpo_hours' => 24]);
    $tenant = Tenant::create(['name' => 'Backup Status '.Str::random(4), 'legal_name' => 'Backup Status Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $other = Tenant::create(['name' => 'Other Status '.Str::random(4), 'legal_name' => 'Other Status Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    Sanctum::actingAs(healthMember($tenant, 'admin'));
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $this->withHeaders($headers)->getJson('/api/v1/system/backups/status')->assertOk()->assertJsonPath('data.state', 'never');

    healthBackup($other, TenantBackup::COMPLETED, '2027-03-10 11:00:00');
    $this->withHeaders($headers)->getJson('/api/v1/system/backups/status')->assertJsonPath('data.state', 'never');

    healthBackup($tenant, TenantBackup::COMPLETED, '2027-03-08 12:00:00');
    $this->withHeaders($headers)->getJson('/api/v1/system/backups/status')->assertJsonPath('data.state', 'stale')->assertJsonPath('data.age_hours', 48);

    healthBackup($tenant, TenantBackup::COMPLETED, '2027-03-10 06:00:00');
    $this->withHeaders($headers)->getJson('/api/v1/system/backups/status')->assertJsonPath('data.state', 'ok')->assertJsonPath('data.completed_count', 2);

    healthBackup($tenant, TenantBackup::FAILED, '2027-03-10 09:00:00');
    $this->withHeaders($headers)->getJson('/api/v1/system/backups/status')->assertJsonPath('data.state', 'failing')->assertJsonPath('data.last_error', 'disk full');
});

test('only owners and administrators read the backup status', function (): void {
    $tenant = Tenant::create(['name' => 'Backup Role '.Str::random(4), 'legal_name' => 'Backup Role Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    Sanctum::actingAs(healthMember($tenant, 'member'));

    $this->withHeaders(['X-Tenant-Id' => (string) $tenant->id])->getJson('/api/v1/system/backups/status')->assertForbidden();
});
