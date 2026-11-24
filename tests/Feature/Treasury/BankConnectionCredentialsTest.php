<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Models\BankConnection;

uses(RefreshDatabase::class);

test('bank connection credentials are stored encrypted and read back as an array', function (): void {
    $tenant = Tenant::create(['name' => 'Banca SA', 'legal_name' => 'Banca SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($tenant);

    $connection = BankConnection::query()->create([
        'tenant_id' => $tenant->id,
        'protocol' => 'open_banking_psd2',
        'provider_name' => 'Banca',
        'auth_credentials_json' => ['client_id' => 'id-123', 'client_secret' => 'segreto'],
    ]);

    $stored = (string) DB::table('bank_connections')->where('id', $connection->id)->value('auth_credentials_json');

    expect($stored)->not->toContain('segreto')
        ->and(json_decode($stored, true))->toBeNull()
        ->and(BankConnection::query()->findOrFail($connection->id)->auth_credentials_json)->toBe(['client_id' => 'id-123', 'client_secret' => 'segreto']);
});
