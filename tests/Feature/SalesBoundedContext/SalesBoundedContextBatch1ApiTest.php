<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\SalesBoundedContextBatch1LocalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Helvetia Sales '.Str::random(4),
        'legal_name' => 'Helvetia Sales AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $this->owner = User::create([
        'name' => 'Owner '.Str::random(4),
        'email' => Str::random(8).'@sd139.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'it',
    ]);
    $this->owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->owner);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('executes the end-to-end Mod 033-042 pipeline across Customer 360, Lead, Forecast, CPQ, Framework Order, Pricing, Subscription, DDT, Commission & Portal', function (): void {
    // Mod 033: Customer 360° Dossier (CH UID + QR-IBAN)
    $dossier = $this->postJson('/api/v1/sales-bounded-context/dossiers', [
        'customer_code' => 'CH-360-01',
        'company_name' => 'Zurich Robotics AG',
        'hierarchy_role' => 'holding',
        'country' => 'CH',
        'currency' => 'CHF',
        'uid_number' => 'CHE-116.281.710',
        'qr_iban' => 'CH4431999123000889012',
        'credit_limit' => 120000,
        'current_exposure' => 10000,
    ])->assertCreated()->json('data');
    expect($dossier['uid_number'])->toBe('CHE-116.281.710');

    // Mod 034: Omnichannel Lead Capture & Deduplication
    $lead1 = $this->postJson('/api/v1/sales-bounded-context/leads', [
        'full_name' => 'Elena Keller',
        'company_name' => 'Zurich Robotics AG',
        'email' => 'elena@zurichrobotics.ch',
        'country' => 'CH',
        'source_channel' => 'quote_request',
        'fiscal_identifier' => 'CHE-116.281.710',
    ])->assertCreated()->json('data');
    expect($lead1['is_duplicate'])->toBeFalse()
        ->and($lead1['total_score'])->toBeGreaterThanOrEqual(75);

    $lead2 = $this->postJson('/api/v1/sales-bounded-context/leads', [
        'full_name' => 'Elena Keller',
        'email' => 'elena@zurichrobotics.ch',
        'country' => 'CH',
    ])->assertCreated()->json('data');
    expect($lead2['is_duplicate'])->toBeTrue();

    // Mod 035: Opportunity Pipeline & Weighted Forecast
    $opp = $this->postJson('/api/v1/sales-bounded-context/opportunities', [
        'dossier_id' => $dossier['id'],
        'lead_id' => $lead1['id'],
        'title' => 'Robotics ERP Rollout',
        'kanban_stage' => 'negotiation',
        'win_probability_pct' => 80,
        'gross_amount' => 50000,
        'currency' => 'CHF',
        'sales_quota_target' => 100000,
    ])->assertCreated()->json('data');
    expect((float) $opp['weighted_amount'])->toBe(40000.0)
        ->and($opp['forecast_category'])->toBe('commit');

    // Mod 036: CPQ Quote + eIDAS/ZertES Signature + 1-Click Order Conversion
    $quote = $this->postJson('/api/v1/sales-bounded-context/quotes', [
        'dossier_id' => $dossier['id'],
        'opportunity_id' => $opp['id'],
        'title' => 'Robotics CPQ Proposal',
        'currency' => 'CHF',
        'signature_standard' => 'zertes_qes',
        'lines' => [
            ['description' => 'ERP License', 'quantity' => 1, 'unit_price' => 40000, 'discount_pct' => 10, 'tax_rate' => 8.1, 'is_optional' => false, 'is_selected' => true],
        ],
    ])->assertCreated()->json('data');

    $signedQuote = $this->postJson("/api/v1/sales-bounded-context/quotes/{$quote['id']}/sign", [
        'signature_standard' => 'zertes_qes',
        'signer_name' => 'Elena Keller',
        'signer_email' => 'elena@zurichrobotics.ch',
    ])->assertOk()->json('data');
    expect($signedQuote['signature_status'])->toBe('signed');

    $convertedQuote = $this->postJson("/api/v1/sales-bounded-context/quotes/{$quote['id']}/convert-to-order")
        ->assertOk()
        ->json('data');
    expect($convertedQuote['status'])->toBe('converted')
        ->and($convertedQuote['converted_order_id'])->not->toBeNull();

    // Mod 037: Framework Agreement & ATP/CTP Call-off Order
    $fw = $this->postJson('/api/v1/sales-bounded-context/orders', [
        'order_kind' => 'framework_agreement',
        'dossier_id' => $dossier['id'],
        'currency' => 'CHF',
        'ceiling_amount' => 100000,
    ])->assertCreated()->json('data');

    $callOff = $this->postJson('/api/v1/sales-bounded-context/orders', [
        'order_kind' => 'sales_order',
        'framework_order_id' => $fw['id'],
        'dossier_id' => $dossier['id'],
        'currency' => 'CHF',
        'atp_qty' => 6,
        'ctp_qty' => 4,
        'requested_qty' => 10,
        'shipped_qty' => 6,
        'total_amount' => 25000,
    ])->assertCreated()->json('data');
    expect($callOff['fulfillment_status'])->toBe('partial_shipped')
        ->and((float) $callOff['backorder_qty'])->toBe(4.0);

    // Mod 038: Pricing Cascading Discounts & Margin Guardrail Approval
    $pricing = $this->postJson('/api/v1/sales-bounded-context/pricing-guardrails', [
        'name' => 'Key Account Promo',
        'currency' => 'CHF',
        'base_unit_price' => 100,
        'unit_cost' => 75,
        'cascading_discounts' => [10, 10],
        'promo_discount_pct' => 5,
        'min_margin_pct' => 15,
    ])->assertCreated()->json('data');
    expect($pricing['guardrail_breached'])->toBeTrue()
        ->and($pricing['approval_status'])->toBe('pending_manager_approval');

    $approved = $this->postJson("/api/v1/sales-bounded-context/pricing-guardrails/{$pricing['id']}/approve-override", [
        'reason' => 'Strategic deal',
    ])->assertOk()->json('data');
    expect($approved['approval_status'])->toBe('approved_override');

    // Mod 039: Recurring Subscription + Pro-Rata + LIK Indexation
    $sub = $this->postJson('/api/v1/sales-bounded-context/subscriptions', [
        'dossier_id' => $dossier['id'],
        'plan_name' => 'Enterprise SLA',
        'billing_cycle' => 'monthly',
        'currency' => 'CHF',
        'base_recurring_amount' => 1000,
        'pro_rata_active_days' => 15,
        'pro_rata_period_days' => 30,
        'indexation_type' => 'SWISS_LIK',
    ])->assertCreated()->json('data');
    expect((float) $sub['pro_rata_adjustment_amount'])->toBe(500.0);

    $revalued = $this->postJson("/api/v1/sales-bounded-context/subscriptions/{$sub['id']}/transition", [
        'action' => 'revalue_index',
        'index_rate_pct' => 2.0,
    ])->assertOk()->json('data');
    expect((float) $revalued['revalued_amount'])->toBe(1020.0);

    // Mod 040: DDT & Deferred Summary Invoicing (TD24)
    $ddt = $this->postJson('/api/v1/sales-bounded-context/ddt-invoices', [
        'dossier_id' => $dossier['id'],
        'document_kind' => 'ddt_it',
        'document_number' => 'DDT-TEST-01',
        'currency' => 'EUR',
        'total_net' => 1000,
        'tax_rate' => 22,
    ])->assertCreated()->json('data');
    expect($ddt['document_number'])->toBe('DDT-TEST-01');

    $deferred = $this->postJson('/api/v1/sales-bounded-context/ddt-invoices', [
        'dossier_id' => $dossier['id'],
        'document_kind' => 'deferred_summary_invoice',
        'grouped_ddt_numbers' => ['DDT-TEST-01'],
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect($deferred['sdi_doc_type'])->toBe('TD24')
        ->and((float) $deferred['total_gross'])->toBe(1220.0);

    // Mod 041: Sales Commission Statement & Enasarco
    $commission = $this->postJson('/api/v1/sales-bounded-context/commissions', [
        'agent_name' => 'Marco Rossi',
        'agent_jurisdiction' => 'IT',
        'accrual_basis' => 'collected',
        'commissionable_base' => 100000,
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect((float) $commission['gross_commission_amount'])->toBe(6000.0)
        ->and((float) $commission['enasarco_agent_withholding'])->toBe(510.0);

    // Mod 042: Self-Service Portal Payment (TWINT)
    $portal = $this->postJson('/api/v1/sales-bounded-context/portal-transactions', [
        'dossier_id' => $dossier['id'],
        'transaction_type' => 'online_payment',
        'payment_provider' => 'twint',
        'amount' => 1500,
        'currency' => 'CHF',
    ])->assertCreated()->json('data');
    expect($portal['payment_provider'])->toBe('twint')
        ->and((float) $portal['open_balance_after'])->toBe(8500.0);

    // Summary & Overview
    $this->getJson("/api/v1/sales-bounded-context/dossiers/{$dossier['id']}/summary")->assertOk();
    $this->getJson('/api/v1/sales-bounded-context/overview')->assertOk();
});

it('seeds all 5 locales idempotently and isolates dossiers across tenants', function (): void {
    $this->seed(SalesBoundedContextBatch1LocalizationSeeder::class);
    $this->seed(SalesBoundedContextBatch1LocalizationSeeder::class);

    foreach (['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'] as $locale) {
        $count = DB::table('translations')
            ->whereNull('tenant_id')
            ->where('locale', $locale)
            ->where('key', 'SBC.TITLE')
            ->count();
        expect($count)->toBe(1);
    }

    $otherTenant = Tenant::create([
        'name' => 'Other Co '.Str::random(4),
        'legal_name' => 'Other Co AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $otherUser = User::create([
        'name' => 'Other '.Str::random(4),
        'email' => Str::random(8).'@other-sd139.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'de',
    ]);
    $otherUser->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    SbcCustomerDossier::create([
        'tenant_id' => $this->tenant->id,
        'customer_code' => 'ISO-001',
        'company_name' => 'Tenant One AG',
        'country' => 'CH',
    ]);

    app(TenantContext::class)->setTenant($otherTenant);
    Sanctum::actingAs($otherUser);
    $this->withHeaders(['X-Tenant-Id' => (string) $otherTenant->id]);

    $this->getJson('/api/v1/sales-bounded-context/dossiers')->assertOk()->assertJsonCount(0, 'data');
});
