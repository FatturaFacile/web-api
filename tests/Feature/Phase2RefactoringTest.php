<?php

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderInterface;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExchangeRate\EuropeanCentralBankProvider;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Listeners\ProcurementAccountingListener;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Phase 2 Test Tenant',
        'legal_name' => 'Phase 2 Test Tenant SRL',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create([
        'name' => 'Phase 2 Auditor',
        'email' => 'phase2.'.Str::random(6).'@fatturafacile.eu',
        'password' => bcrypt('StrongPass123!'),
        'locale' => 'it',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);
});

test('procurement accounting listener uses dynamic commitment amount instead of hardcoded 100', function (): void {
    $capturedPayload = null;

    $postingServiceMock = Mockery::mock(DoubleEntryPostingServiceInterface::class);
    $fakeEntry = new JournalEntry(['tenant_id' => $this->tenant->id]);
    $postingServiceMock->shouldReceive('createAndPost')
        ->once()
        ->with(Mockery::on(function ($payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        }))
        ->andReturn($fakeEntry);

    // Mock resolveFiscalPeriod & resolveAccount in listener if needed, or create chart of account & fiscal period
    $listener = new ProcurementAccountingListener($postingServiceMock, app(FiscalPeriodResolver::class));

    // Create DB dependencies for listener
    DB::table('fiscal_years')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'year_code' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fyId = DB::table('fiscal_years')->where('tenant_id', $this->tenant->id)->first()->id;

    $periodId = (string) Str::uuid();
    DB::table('fiscal_periods')->insert([
        'id' => $periodId,
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $fyId,
        'period_name' => 'Period 11',
        'period_number' => 11,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $event = new PurchaseOrderCancelledEvent(
        purchaseOrderId: (string) Str::uuid(),
        poNumber: 'PO-2026-999',
        reason: 'Vendor insolvency',
        tenantId: $this->tenant->id,
        companyId: (string) Str::uuid(),
        cancelledAmount: 4850.75
    );

    $listener->handlePurchaseOrderCancelled($event);

    expect($capturedPayload)->not->toBeNull()
        ->and($capturedPayload['journal_type'])->toBe('reversing')
        ->and($capturedPayload['lines'][0]['debit_amount'])->toBe('4850.7500')
        ->and($capturedPayload['lines'][1]['credit_amount'])->toBe('4850.7500');
});

test('european central bank provider returns valid currency pairs', function (): void {
    $provider = new EuropeanCentralBankProvider;
    $rates = $provider->getRates();

    expect($rates)->toBeArray()
        ->and(count($rates))->toBeGreaterThan(0);

    $sources = array_column($rates, 'source');
    $targets = array_column($rates, 'target');

    expect($sources)->toContain('EUR')
        ->and($targets)->toContain('CHF');
});

test('sync exchange rates endpoint populates exchange rates via provider', function (): void {
    $fakeProvider = new class implements ExchangeRateProviderInterface
    {
        public function getRates(string $baseCurrency = 'EUR'): array
        {
            return [
                ['source' => 'EUR', 'target' => 'CHF', 'rate' => 0.9550],
                ['source' => 'USD', 'target' => 'EUR', 'rate' => 0.9150],
            ];
        }
    };

    $this->app->instance(ExchangeRateProviderInterface::class, $fakeProvider);

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Tenant' => $this->tenant->id])
        ->postJson('/api/v1/settings/exchange-rates/sync');

    $response->assertOk()
        ->assertJsonPath('data.synced_pairs', 2);

    expect(ExchangeRate::where('source', 'EUR')->where('target', 'CHF')->exists())->toBeTrue()
        ->and(ExchangeRate::where('source', 'USD')->where('target', 'EUR')->exists())->toBeTrue();
});

test('canonical routing definitions are respected and duplicates removed', function (): void {
    // /api/v1/mfg alias removed
    $this->actingAs($this->user)
        ->withHeaders(['X-Tenant' => $this->tenant->id])
        ->getJson('/api/v1/mfg/boms')
        ->assertNotFound();

    // Canonical /api/v1/manufacturing/boms works
    $this->actingAs($this->user)
        ->withHeaders(['X-Tenant' => $this->tenant->id])
        ->getJson('/api/v1/manufacturing/boms')
        ->assertOk();
});
