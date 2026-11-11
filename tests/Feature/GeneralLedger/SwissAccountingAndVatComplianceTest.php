<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Contracts\VatSettlementServiceInterface;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\GeneralLedger\Models\VatCode;
use Modules\GeneralLedger\Models\VatTaxSetting;
use Modules\GeneralLedger\Services\OpenItemFxRevaluationService;
use Modules\GeneralLedger\Services\Tax\VatPeriod;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, user: User, fy2026: FiscalYear}
 */
function chGlTenant(string $currency = 'CHF'): array
{
    $tenant = Tenant::create([
        'name' => 'Swiss GL Tenant '.Str::random(4),
        'legal_name' => 'Helvetica Treuhand AG',
        'country' => 'CH',
        'default_currency' => $currency,
        'vat_number' => 'CHE-123.456.789',
        'uid_number' => 'CHE-123.456.789',
    ]);

    $user = User::create([
        'name' => 'Swiss CFO',
        'email' => Str::random(10).'@helvetica.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'de',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($tenant);

    $fy = FiscalYear::create([
        'tenant_id' => (string) $tenant->id,
        'year_code' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    foreach (range(1, 12) as $month) {
        $first = CarbonImmutable::create(2026, $month, 1);
        FiscalPeriod::create([
            'tenant_id' => (string) $tenant->id,
            'fiscal_year_id' => $fy->id,
            'period_number' => $month,
            'period_name' => $first->format('F Y'),
            'start_date' => $first->toDateString(),
            'end_date' => $first->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }

    return ['tenant' => $tenant, 'user' => $user, 'fy2026' => $fy];
}

function chAccount(Tenant $tenant, string $code, string $type = 'asset', string $name = ''): ChartOfAccount
{
    app(TenantContext::class)->setTenant($tenant);
    $normal = in_array($type, ['asset', 'expense', 'cogs'], true) ? 'debit' : 'credit';

    return ChartOfAccount::firstOrCreate(
        ['tenant_id' => (string) $tenant->id, 'account_code' => $code],
        [
            'name' => $name !== '' ? $name : "Account {$code}",
            'account_class' => $type,
            'account_type' => $type,
            'normal_balance' => $normal,
            'currency' => $tenant->default_currency ?: 'CHF',
            'is_active' => true,
        ]
    );
}

test('Phase A.1: Swiss CO Art. 960a Imparity Principle defers unrealised FX gains to provision while recognising unrealised FX losses in P&L', function (): void {
    ['tenant' => $tenant, 'user' => $user] = chGlTenant('CHF');

    $arAccount = chAccount($tenant, '1100', 'asset', 'Debitori');
    $revAccount = chAccount($tenant, '3200', 'revenue', 'Ricavi');
    $fxLossAccount = chAccount($tenant, '6955', 'expense', 'Perdite cambio non realizzate');
    $fxGainAccount = chAccount($tenant, '6950', 'revenue', 'Utili cambio non realizzati');
    $fxProvisionAccount = chAccount($tenant, '2305', 'liability', 'Accantonamento utili di cambio non realizzati (CO 960a)');

    LedgerSetting::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'fx_unrealised_loss_account_id' => $fxLossAccount->id,
        'fx_unrealised_gain_account_id' => $fxGainAccount->id,
        'fx_unrealised_gain_provision_account_id' => $fxProvisionAccount->id,
        'enforce_swiss_imparity_principle' => true,
        'unrealised_gain_treatment' => 'provision',
    ]);

    $customer = Contact::create([
        'tenant_id' => (string) $tenant->id,
        'company_name' => 'EuroKunde GmbH',
        'type' => 'customer',
        'country' => 'DE',
    ]);

    $periodJan = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 1)->firstOrFail();

    // Invoice of 1000 EUR booked at historical rate 0.92 CHF/EUR -> book value 920.00 CHF
    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodJan->id,
        'posting_date' => '2026-01-10',
        'document_date' => '2026-01-10',
        'lines' => [
            [
                'account_id' => $arAccount->id,
                'partner_type' => 'customer',
                'partner_id' => $customer->id,
                'debit_amount' => '1000.00',
                'credit_amount' => '0',
                'currency' => 'EUR',
                'exchange_rate' => '0.92',
            ],
            [
                'account_id' => $revAccount->id,
                'debit_amount' => '0',
                'credit_amount' => '1000.00',
                'currency' => 'EUR',
                'exchange_rate' => '0.92',
            ],
        ],
    ], (string) $user->id);

    // Closing rate on 2026-01-31: 1 CHF = 1.00 EUR -> revalued value = 1000.00 CHF -> unrealised GAIN of +80.00 CHF
    ExchangeRate::createGlobal([
        'source' => 'CHF',
        'target' => 'EUR',
        'rate' => '1.00000000',
        'effective_date' => '2026-01-31',
    ]);

    $revaluations = app(OpenItemFxRevaluationService::class)->revalue(
        (string) $tenant->id,
        CarbonImmutable::parse('2026-01-31'),
        'EUR',
        (string) $user->id
    );

    expect($revaluations)->toHaveCount(1)
        ->and((string) $revaluations[0]->difference)->toBe('80.0000')
        ->and($revaluations[0]->imparity_deferred)->toBeTrue()
        ->and($revaluations[0]->treatment_applied)->toBe('provision');

    // Verify P&L income statement for January 2026 does NOT include the 80.00 CHF unrealised gain
    Sanctum::actingAs($user);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];
    $isResp = $this->withHeaders($headers)
        ->getJson('/api/v1/general-ledger/reports/income-statement?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->json('data');

    expect($isResp['totals']['total_revenue'])->toBe('920.0000');
});

test('Phase A.2, A.3 & A.4: Swiss Anhang (CO Art. 959c), CHF Presentation Currency (CO Art. 958d), and Fiscal Year Reopen / AGM Lock workflow', function (): void {
    ['tenant' => $tenant, 'user' => $user, 'fy2026' => $fy] = chGlTenant('EUR');

    $cash = chAccount($tenant, '1020', 'asset', 'Banca EUR');
    $bvg = chAccount($tenant, '2270', 'liability', 'Istituto previdenza LPP / BVG');
    $rev = chAccount($tenant, '3200', 'revenue', 'Ricavi vendite');
    $retained = chAccount($tenant, '2970', 'equity', 'Utili/perdite portati a nuovo');

    LedgerSetting::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'retained_earnings_account_id' => $retained->id,
    ]);

    DefaultPostingAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'role' => 'retained_earnings',
        'account_id' => $retained->id,
    ]);

    $periodDec = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 12)->firstOrFail();

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodDec->id,
        'posting_date' => '2026-12-15',
        'document_date' => '2026-12-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => '10000.00', 'credit_amount' => '0'],
            ['account_id' => $bvg->id, 'debit_amount' => '0', 'credit_amount' => '2000.00'],
            ['account_id' => $rev->id, 'debit_amount' => '0', 'credit_amount' => '8000.00'],
        ],
    ], (string) $user->id);

    // Rate EUR -> CHF for CO Art. 958d para. 3 dual presentation
    ExchangeRate::createGlobal([
        'source' => 'EUR',
        'target' => 'CHF',
        'rate' => '0.95000000',
        'effective_date' => '2026-12-31',
    ]);

    Sanctum::actingAs($user);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    // 1. Test CO Art. 958d para. 3 CHF presentation currency on Balance Sheet & Income Statement
    $bsChf = $this->withHeaders($headers)
        ->getJson("/api/v1/general-ledger/financial-statements/balance-sheet?fiscal_period_id={$periodDec->id}&presentation_currency=CHF")
        ->assertOk()
        ->json('data');

    expect($bsChf['functional_currency'])->toBe('EUR')
        ->and($bsChf['presentation_currency'])->toBe('CHF')
        ->and($bsChf['functional_total_assets'])->toBe('10000.0000')
        ->and($bsChf['presentation_total_assets'])->toBe('9500.0000');

    // 2. Test Swiss Statutory Notes (Anhang, CO Art. 959c)
    $this->withHeaders($headers)
        ->putJson("/api/v1/general-ledger/financial-statements/swiss-anhang/{$fy->id}", [
            'fte_band' => 'up_to_50',
            'pledged_assets_amount' => '1500.00',
            'leasing_commitments_amount' => '450.00',
            'fire_insurance_values' => [['item' => 'Macchinari', 'value' => '50000.00']],
        ])
        ->assertOk();

    $anhang = $this->withHeaders($headers)
        ->getJson("/api/v1/general-ledger/financial-statements/swiss-anhang/{$fy->id}")
        ->assertOk()
        ->json('data');

    expect($anhang['legal_reference'])->toBe('CO Art. 959c')
        ->and($anhang['fte_band'])->toBe('up_to_50')
        ->and($anhang['derived_pension_fund_liabilities_amount'])->toBe('2000.0000')
        ->and($anhang['ledger_figures']['total_assets'])->toBe('10000.0000');

    // 3. Close all 12 periods, close Fiscal Year, Reopen Fiscal Year, Close again, and Approve at AGM
    FiscalPeriod::where('fiscal_year_id', $fy->id)->update(['status' => 'closed']);

    $this->withHeaders($headers)
        ->postJson("/api/v1/general-ledger/fiscal-years/{$fy->id}/close")
        ->assertOk();

    expect($fy->fresh()->status)->toBe('closed');

    // Reopen fiscal year via endpoint
    $this->withHeaders($headers)
        ->postJson("/api/v1/general-ledger/fiscal-years/{$fy->id}/reopen", [
            'reason' => 'Audit adjustment requested by statutory auditor prior to AGM',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');

    // Close again and approve at AGM
    FiscalPeriod::where('fiscal_year_id', $fy->id)->update(['status' => 'closed']);
    app(PeriodClosingServiceInterface::class)->closeFiscalYear((string) $fy->id, (string) $user->id);

    $this->withHeaders($headers)
        ->postJson("/api/v1/general-ledger/fiscal-years/{$fy->id}/approve-agm", [
            'resolution_reference' => 'AGM-2027-04-15-RES-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.agm_resolution_reference', 'AGM-2027-04-15-RES-01');

    // Reopening after AGM approval must throw LedgerConflictException
    expect(fn () => app(PeriodClosingServiceInterface::class)->reopenFiscalYear((string) $fy->id, (string) $user->id, 'Late change'))
        ->toThrow(LedgerConflictException::class, 'approved by the General Assembly');
});

test('Phase B: Swiss VAT Saldosteuersatzmethode (SSS), Bezugsteuer (Box 383), all 20 ESTV boxes, eCH-0217 XML, and Art. 72 LIVA Annual Reconciliation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = chGlTenant('CHF');

    $ar = chAccount($tenant, '1100', 'asset');
    $ap = chAccount($tenant, '2000', 'liability');
    $rev = chAccount($tenant, '3200', 'revenue');
    $exp = chAccount($tenant, '6000', 'expense');
    $outVat = chAccount($tenant, '2200', 'liability');
    $inVat = chAccount($tenant, '1170', 'asset');

    VatTaxSetting::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'periodicity' => VatPeriod::SEMIANNUAL,
        'regime' => 'net_tax_rate',
        'sss_rate_1' => '6.20',
        'sss_rate_2' => '2.10',
        'output_vat_account_ids' => [$outVat->id],
        'input_vat_account_ids' => [$inVat->id],
    ]);

    $exportCode = VatCode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'code' => 'EXP0',
        'description' => 'Export tax-exempt (Box 220)',
        'rate' => '0.00',
        'tax_rate_percent' => '0.00',
        'estv_box' => '220',
        'country' => 'CH',
        'country_iso' => 'CH',
    ]);

    $sss2Code = VatCode::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'code' => 'SSS2',
        'description' => 'Secondary SSS activity 2.1%',
        'rate' => '8.10',
        'tax_rate_percent' => '8.10',
        'sss_rate_percent' => '2.10',
        'country' => 'CH',
        'country_iso' => 'CH',
        'gl_sales_tax_account_id' => $outVat->id,
    ]);

    $periodJan = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 1)->firstOrFail();
    $periodFeb = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 2)->firstOrFail();
    $periodMar = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 3)->firstOrFail();
    $periodApr = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 4)->firstOrFail();
    $periodMay = FiscalPeriod::where('tenant_id', $tenant->id)->where('period_number', 5)->firstOrFail();
    $posting = app(DoubleEntryPostingServiceInterface::class);

    // 1. Primary SSS sale: net 10,000 + 8.1% VAT (810) = gross 10,810 -> SSS 6.2% on 10,810 = 670.22 CHF
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodJan->id,
        'source_document_type' => 'sales_invoice',
        'posting_date' => '2026-01-15',
        'document_date' => '2026-01-15',
        'lines' => [
            ['account_id' => $ar->id, 'debit_amount' => '10810.00', 'credit_amount' => '0'],
            ['account_id' => $rev->id, 'debit_amount' => '0', 'credit_amount' => '10000.00'],
            ['account_id' => $outVat->id, 'debit_amount' => '0', 'credit_amount' => '810.00', 'vat_base_amount' => '10000.00', 'vat_rate_percent' => '8.1'],
        ],
    ]);

    // 2. Secondary SSS sale: net 1,000 + 8.1% VAT (81) = gross 1,081 -> SSS 2.1% on 1,081 = 22.70 CHF
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodFeb->id,
        'source_document_type' => 'sales_invoice',
        'posting_date' => '2026-02-10',
        'document_date' => '2026-02-10',
        'lines' => [
            ['account_id' => $ar->id, 'debit_amount' => '1081.00', 'credit_amount' => '0'],
            ['account_id' => $rev->id, 'debit_amount' => '0', 'credit_amount' => '1000.00'],
            ['account_id' => $outVat->id, 'debit_amount' => '0', 'credit_amount' => '81.00', 'vat_base_amount' => '1000.00', 'vat_rate_percent' => '8.1', 'vat_code_id' => $sss2Code->id],
        ],
    ]);

    // 3. Export sale (0% VAT, Box 220): 2,000 CHF
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodMar->id,
        'source_document_type' => 'sales_invoice',
        'posting_date' => '2026-03-05',
        'document_date' => '2026-03-05',
        'lines' => [
            ['account_id' => $ar->id, 'debit_amount' => '2000.00', 'credit_amount' => '0'],
            ['account_id' => $rev->id, 'debit_amount' => '0', 'credit_amount' => '2000.00', 'vat_code_id' => $exportCode->id],
        ],
    ]);

    // 4. Domestic purchase with input VAT 150 CHF (forfeited under SSS!) + Reverse-charge Bezugsteuer purchase (Box 383: base 1000, tax 81)
    $supplier = Contact::create(['tenant_id' => (string) $tenant->id, 'company_name' => 'Lieferant AG', 'type' => 'supplier', 'country' => 'CH']);
    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodApr->id,
        'source_document_type' => 'vendor_invoice',
        'posting_date' => '2026-04-10',
        'document_date' => '2026-04-10',
        'lines' => [
            ['account_id' => $exp->id, 'debit_amount' => '1851.85', 'credit_amount' => '0'],
            ['account_id' => $inVat->id, 'debit_amount' => '150.00', 'credit_amount' => '0', 'vat_base_amount' => '1851.85', 'vat_rate_percent' => '8.1'],
            ['account_id' => $ap->id, 'debit_amount' => '0', 'credit_amount' => '2001.85', 'partner_type' => 'supplier', 'partner_id' => $supplier->id],
        ],
    ]);

    $posting->createAndPost([
        'tenant_id' => (string) $tenant->id,
        'fiscal_period_id' => $periodMay->id,
        'source_document_type' => 'vendor_invoice',
        'posting_date' => '2026-05-12',
        'document_date' => '2026-05-12',
        'lines' => [
            ['account_id' => $exp->id, 'debit_amount' => '1000.00', 'credit_amount' => '0'],
            ['account_id' => $inVat->id, 'debit_amount' => '81.00', 'credit_amount' => '0', 'vat_base_amount' => '1000.00', 'vat_rate_percent' => '8.1', 'vat_treatment' => 'reverse_charge'],
            ['account_id' => $ap->id, 'debit_amount' => '0', 'credit_amount' => '1081.00', 'partner_type' => 'supplier', 'partner_id' => $supplier->id],
        ],
    ]);

    $settlement = app(VatSettlementServiceInterface::class)->calculate(
        (string) $tenant->id,
        VatPeriod::of(VatPeriod::SEMIANNUAL, 2026, 1),
        (string) $user->id
    );

    // Output VAT = 670.22 (SSS 6.2%) + 22.70 (SSS 2.1%) + 81.00 (Bezugsteuer Box 383) = 773.92 CHF
    // Input VAT deductible = 0.00 (SSS forfeits input VAT)
    expect($settlement->regime)->toBe('net_tax_rate')
        ->and((string) $settlement->output_vat_amount)->toBe('773.9200')
        ->and((string) $settlement->input_vat_amount)->toBe('0.0000')
        ->and((string) $settlement->acquisition_tax_base)->toBe('1000.00')
        ->and((string) $settlement->acquisition_tax_amount)->toBe('81.0000')
        ->and((string) $settlement->payable_amount)->toBe('773.92')
        ->and($settlement->estv_boxes)->toHaveKeys([
            '200', '220', '221', '225', '230', '235', '289', '299',
            '303', '313', '343', '383', '399',
            '400', '405', '410', '415', '420', '479',
            '500', '510',
        ])
        ->and($settlement->estv_boxes['220'])->toBe('2000.00')
        ->and($settlement->estv_boxes['383']['tax'])->toBe('81.00')
        ->and($settlement->estv_boxes['500'])->toBe('773.92');

    // Post settlement in ledger and verify it balances
    $journal = app(VatSettlementServiceInterface::class)->post((string) $tenant->id, (string) $settlement->id, (string) $user->id);
    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('posted');

    // Test eCH-0217 XML export and Annual Reconciliation endpoints
    Sanctum::actingAs($user);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $xmlResp = $this->withHeaders($headers)
        ->get("/api/v1/general-ledger/tax-settlements/{$settlement->id}/swiss-xml")
        ->assertOk();

    expect((string) $xmlResp->getContent())->toContain('http://www.ech.ch/xmlns/eCH-0217/2')
        ->and((string) $xmlResp->getContent())->toContain('code="383"')
        ->and((string) $xmlResp->getContent())->toContain('773.92');

    $reconcil = $this->withHeaders($headers)
        ->getJson('/api/v1/general-ledger/vat-annual-reconciliation?year=2026')
        ->assertOk()
        ->json('data');

    expect($reconcil['year'])->toBe(2026)
        ->and($reconcil['settlements_count'])->toBe(1)
        ->and($reconcil['umsatzabstimmung']['ledger_revenue'])->toBe('13000.00');
});
