<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\SalesBoundedContextBatch2LocalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\SalesBoundedContext\Models\SbcMarketingCampaign;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Alpine Extended Sales '.Str::random(4),
        'legal_name' => 'Alpine Extended Sales AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $this->owner = User::create([
        'name' => 'Owner '.Str::random(4),
        'email' => Str::random(8).'@sd139-b2.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'it',
    ]);
    $this->owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->owner);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('executes all Batch 2 modules (Mod 155-358) integrated with Batch 1 dossiers, orders, and credit notes', function (): void {
    // Batch 1 prerequisite: Dossier (performing + insolvent) & Invoice
    $performingDossier = $this->postJson('/api/v1/sales-bounded-context/dossiers', [
        'customer_code' => 'B2-CH-01',
        'company_name' => 'Helvetia Retail AG',
        'hierarchy_role' => 'holding',
        'country' => 'CH',
        'currency' => 'CHF',
        'uid_number' => 'CHE-116.281.710',
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/sales-bounded-context/dossiers', [
        'customer_code' => 'B2-CH-INSOLV',
        'company_name' => 'Insolvent Co SA',
        'hierarchy_role' => 'subsidiary',
        'country' => 'CH',
        'currency' => 'CHF',
        'overdue_balance' => 5000,
        'insolvency_status' => 'credit_hold',
    ])->assertCreated();

    // Mod 155: RFM Marketing Campaign + Insolvent exclusion + GDPR/nLPD/LCSl check
    $campaign = $this->postJson('/api/v1/sales-bounded-context/marketing-campaigns', [
        'dossier_id' => $performingDossier['id'],
        'name' => 'Spring Champions DEM',
        'channel' => 'dem_email',
        'rfm_recency_score' => 5,
        'rfm_frequency_score' => 5,
        'rfm_monetary_score' => 5,
        'exclude_insolvent_overdue' => true,
        'gdpr_nlpd_lcsl_consent_verified' => true,
        'target_audience_count' => 100,
        'opted_out_suppressed_count' => 5,
        'campaign_cost' => 1000,
        'attributed_revenue' => 11000,
        'currency' => 'CHF',
    ])->assertCreated()->json('data');
    expect($campaign['rfm_segment'])->toBe('champions')
        ->and($campaign['excluded_insolvent_count'])->toBe(1)
        ->and($campaign['eligible_recipients_count'])->toBe(94)
        ->and((float) $campaign['roi_pct'])->toBe(1000.0);

    // Mod 169: ANAC MePA Tender + CIG/CUP Conversion to Framework Order
    $tender = $this->postJson('/api/v1/sales-bounded-context/tenders', [
        'dossier_id' => $performingDossier['id'],
        'title' => 'Comune di Milano ERP Lot 2',
        'procurement_framework' => 'anac_mepa_dlgs36_2023',
        'contracting_authority' => 'Comune di Milano',
        'cig_code' => 'B123456789',
        'cup_code' => 'J41B26000010001',
        'technical_envelope_score' => 65,
        'economic_envelope_score' => 28,
        'bid_amount' => 180000,
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect((float) $tender['total_award_score'])->toBe(93.0);

    $convertedTender = $this->postJson("/api/v1/sales-bounded-context/tenders/{$tender['id']}/convert-contract")
        ->assertOk()
        ->json('data');
    expect($convertedTender['pipeline_stage'])->toBe('contract_converted')
        ->and($convertedTender['converted_order_id'])->not->toBeNull();

    // Mod 230: Technical-Commercial CPQ Configurator + Super-BOM Explosion
    $cpq = $this->postJson('/api/v1/sales-bounded-context/cpq-configurations', [
        'dossier_id' => $performingDossier['id'],
        'product_family' => 'Modular Packaging Line',
        'selected_options' => ['voltage' => '400V', 'chiller' => 'high_capacity'],
        'discount_pct' => 5,
        'currency' => 'CHF',
    ])->assertCreated()->json('data');
    expect($cpq['has_constraint_conflict'])->toBeFalse()
        ->and($cpq['status'])->toBe('validated');

    // Mod 231: GDO Framework Contract, Tiered PFA Rebate & Credit Note Settlement
    $rebate = $this->postJson('/api/v1/sales-bounded-context/gdo-rebates', [
        'dossier_id' => $performingDossier['id'],
        'gdo_chain_name' => 'Migros / Esselunga',
        'sell_in_volume_amount' => 300000,
        'sell_out_promo_contribution' => 3000,
        'accrued_months_count' => 12,
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect((float) $rebate['resolved_tier_rate_pct'])->toBe(4.0)
        ->and((float) $rebate['annual_pfa_rebate_amount'])->toBe(15000.0);

    $settledRebate = $this->postJson("/api/v1/sales-bounded-context/gdo-rebates/{$rebate['id']}/settle-credit-note")
        ->assertOk()
        ->json('data');
    expect($settledRebate['settlement_status'])->toBe('settled_credit_note')
        ->and($settledRebate['settlement_credit_note_id'])->not->toBeNull();

    // Mod 232: Reverse Logistics Return, Cross-Shipping, B-Stock & Art. 26 DPR 633/72
    $rma = $this->postJson('/api/v1/sales-bounded-context/reverse-returns', [
        'dossier_id' => $performingDossier['id'],
        'return_reason' => 'Damaged display panel',
        'is_advance_cross_shipping' => true,
        'b_stock_grade' => 'grade_b_refurbished',
        'original_invoice_date' => now()->subDays(20)->toDateString(),
        'return_net_amount' => 1000,
        'vat_rate_pct' => 22,
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect($rma['art26_dpr633_72_vat_recoverable'])->toBeTrue()
        ->and((float) $rma['credit_note_gross_amount'])->toBe(1220.0);

    // Mod 233: Van Selling & End-of-Route Reconciliation
    $van = $this->postJson('/api/v1/sales-bounded-context/van-routes', [
        'driver_agent_name' => 'Matteo Fontana',
        'vehicle_plate' => 'TI-448291',
        'loaded_stock_qty' => 100,
        'sold_stock_qty' => 80,
        'returned_unsold_qty' => 20,
        'expected_cash_amount' => 1500,
        'collected_cash_amount' => 1500,
        'electronic_payment_amount' => 900,
        'currency' => 'CHF',
    ])->assertCreated()->json('data');
    expect($van['reconciliation_status'])->toBe('reconciled_balanced')
        ->and($van['bluetooth_escpos_payload'])->toContain('FATTURAFACILE VAN SELLING RECEIPT');

    // Mod 244: Franchising Portal & Royalty Calculation
    $affiliate = $this->postJson('/api/v1/sales-bounded-context/franchising-affiliates', [
        'dossier_id' => $performingDossier['id'],
        'store_name' => 'Lugano Store',
        'sell_out_turnover' => 100000,
        'royalty_rate_pct' => 5,
        'marketing_fund_rate_pct' => 2,
        'network_avg_turnover' => 80000,
        'currency' => 'CHF',
    ])->assertCreated()->json('data');
    expect((float) $affiliate['total_fee_invoiceable'])->toBe(7000.0)
        ->and((float) $affiliate['benchmark_index_pct'])->toBe(125.0);

    // Mod 245: Post-Delivery Survey + Detractor Recovery Alert
    $survey = $this->postJson('/api/v1/sales-bounded-context/surveys', [
        'dossier_id' => $performingDossier['id'],
        'trigger_event' => 'post_delivery_ddt',
        'nps_score' => 4,
        'csat_score' => 2,
        'ces_score' => 3,
    ])->assertCreated()->json('data');
    expect($survey['nps_classification'])->toBe('detractor')
        ->and($survey['detractor_recovery_alert_triggered'])->toBeTrue();

    // Mod 358: Corporate Welfare & CRAL Split Billing
    $welfare = $this->postJson('/api/v1/sales-bounded-context/welfare-conventions', [
        'partner_dossier_id' => $performingDossier['id'],
        'partner_company_name' => 'Alpine Italia Srl',
        'corporate_email_domain' => 'alpine.it',
        'employee_email' => 'mario.rossi@alpine.it',
        'cral_badge_number' => 'CRAL-0091',
        'welfare_regime' => 'it_art51_tuir',
        'gross_basket_amount' => 500,
        'convention_discount_pct' => 10,
        'employer_welfare_split_pct' => 60,
        'currency' => 'EUR',
    ])->assertCreated()->json('data');
    expect((float) $welfare['employer_welfare_quota_amount'])->toBe(270.0)
        ->and((float) $welfare['employee_copay_quota_amount'])->toBe(180.0)
        ->and($welfare['within_statutory_exemption'])->toBeTrue();

    $this->getJson('/api/v1/sales-bounded-context/overview')->assertOk();
    $this->getJson("/api/v1/sales-bounded-context/dossiers/{$performingDossier['id']}/summary")->assertOk();
});

it('seeds Batch 2 5-locale translations idempotently and enforces tenant isolation', function (): void {
    $this->seed(SalesBoundedContextBatch2LocalizationSeeder::class);
    $this->seed(SalesBoundedContextBatch2LocalizationSeeder::class);

    foreach (['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'] as $locale) {
        $count = DB::table('translations')
            ->whereNull('tenant_id')
            ->where('locale', $locale)
            ->where('key', 'SBC.MOD155.TITLE')
            ->count();
        expect($count)->toBe(1);
    }

    SbcMarketingCampaign::create([
        'tenant_id' => $this->tenant->id,
        'campaign_code' => 'ISO-MKT-01',
        'name' => 'Tenant One Campaign',
    ]);

    $otherTenant = Tenant::create([
        'name' => 'Other B2 '.Str::random(4),
        'legal_name' => 'Other B2 AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    $otherUser = User::create([
        'name' => 'Other '.Str::random(4),
        'email' => Str::random(8).'@other-b2.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'de',
    ]);
    $otherUser->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($otherTenant);
    Sanctum::actingAs($otherUser);
    $this->withHeaders(['X-Tenant-Id' => (string) $otherTenant->id]);

    $this->getJson('/api/v1/sales-bounded-context/marketing-campaigns')->assertOk()->assertJsonCount(0, 'data');
});
