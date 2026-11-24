<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function chVatTenant(): array
{
    $tenant = Tenant::create([
        'name' => 'Helvetia Treuhand '.Str::random(4),
        'legal_name' => 'Helvetia Treuhand AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'uid_number' => 'CHE-116.281.710',
        'vat_number' => 'CHE-116.281.710 MWST',
    ]);

    $user = User::create([
        'name' => 'Swiss Accountant',
        'email' => Str::random(10).'@helvetia.ch',
        'password' => bcrypt(Str::password(16)),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid7(), 'role' => 'accountant']);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return [$tenant, $user];
}

test('full Swiss VAT workflow: config, Bezugsteuer detection, LIVA adjustments, calculation, reconciliation, XSD validation, SuisseTax XML export, and tenant isolation', function (): void {
    [$tenant, $user] = chVatTenant();

    // 1. Configure Swiss VAT Effective method with valid UID-MWST
    $this->putJson('/api/v1/swiss-vat/config', [
        'uid_mwst' => 'CHE-116.281.710 MWST',
        'accounting_method' => 'effective',
        'consideration_basis' => 'agreed',
        'reporting_frequency' => 'quarterly',
        'rounding_mode' => 'chf_005',
        'auto_detect_bezugsteuer' => true,
    ])->assertOk()->assertJsonPath('data.uid_mwst', 'CHE-116.281.710 MWST');

    // 2. Seed a foreign cloud supplier bill (DE, no domestic CH VAT) -> auto-detect Bezugsteuer
    $foreignSupplier = Contact::create([
        'tenant_id' => (string) $tenant->id,
        'type' => 'supplier',
        'country' => 'DE',
        'company_name' => 'CloudTech GmbH',
        'vat_number' => 'DE123456789',
    ]);

    DB::table('passive_invoices')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'supplier_id' => (string) $foreignSupplier->id,
        'invoice_number' => 'AWS-2026-001',
        'date' => '2026-02-15',
        'total_amount' => '1000.00',
        'total_net' => '1000.00',
        'total_tax' => '0.00',
        'currency' => 'CHF',
        'exchange_rate' => '1.000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $detectResp = $this->postJson('/api/v1/swiss-vat/bezugsteuer/detect', [
        'period_from' => '2026-01-01',
        'period_to' => '2026-03-31',
    ])->assertOk();

    $bezId = (string) $detectResp->json('data.0.id');
    expect($bezId)->not->toBe('')
        ->and($detectResp->json('data.0.afc_box'))->toBe('383')
        ->and($detectResp->json('data.0.tax_amount_chf'))->toBe('81.00');

    $this->postJson("/api/v1/swiss-vat/bezugsteuer/{$bezId}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    // 3. Record and approve LIVA Art. 32 Degrassazione (+200.00 Box 410) & Art. 31 Autoconsumo (-50.00 Box 415)
    $degId = (string) $this->postJson('/api/v1/swiss-vat/adjustments', [
        'adjustment_type' => 'degrassazione',
        'effective_date' => '2026-02-20',
        'description' => 'Subsequent deduction on asset',
        'adjustment_amount_chf' => '200.00',
    ])->assertCreated()->json('data.id');

    $autoId = (string) $this->postJson('/api/v1/swiss-vat/adjustments', [
        'adjustment_type' => 'autoconsumo',
        'effective_date' => '2026-03-10',
        'description' => 'Private vehicle use',
        'adjustment_amount_chf' => '50.00',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/swiss-vat/adjustments/{$degId}/approve")->assertOk();
    $this->postJson("/api/v1/swiss-vat/adjustments/{$autoId}/approve")->assertOk();

    // 4. Calculate 2026 Q1 return with sales & purchase lines
    $calcResp = $this->postJson('/api/v1/swiss-vat/returns/calculate', [
        'fiscal_year' => 2026,
        'period_type' => 'quarterly',
        'period_number' => 1,
        'sales_lines' => [
            ['net_amount' => '10000.00', 'tax_rate' => '8.10', 'exemption_type' => 'none', 'afc_box' => '303'],
            ['net_amount' => '2000.00', 'tax_rate' => '0.00', 'exemption_type' => 'exempt_with_deduction', 'afc_box' => '220'],
        ],
        'purchase_lines' => [
            ['net_amount' => '1000.00', 'input_vat_amount' => '81.00', 'afc_box' => '400', 'is_deductible' => true],
        ],
    ])->assertOk();

    $returnId = (string) $calcResp->json('data.id');
    expect($calcResp->json('data.quadratura_passed'))->toBeTrue()
        ->and($calcResp->json('data.box_200_total_turnover'))->toBe('12000.00')
        ->and($calcResp->json('data.box_299_taxable_turnover'))->toBe('10000.00')
        ->and($calcResp->json('data.box_399_total_output_tax'))->toBe('891.00'); // 810 + 81 Bezugsteuer

    // 5. Reconciliation schedule & XSD validation
    $this->getJson("/api/v1/swiss-vat/returns/{$returnId}/reconciliation")
        ->assertOk()
        ->assertJsonPath('data.quadratura_passed', true);

    $this->postJson("/api/v1/swiss-vat/returns/{$returnId}/validate")
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.xsd_valid', true);

    // 6. Export SuisseTax XML, list exports, download XML, and finalize return
    $exportId = (string) $this->postJson("/api/v1/swiss-vat/returns/{$returnId}/export-xml")
        ->assertOk()
        ->assertJsonPath('data.xsd_valid', true)
        ->json('data.id');

    $this->getJson("/api/v1/swiss-vat/returns/{$returnId}/exports")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $xmlDownload = $this->get("/api/v1/swiss-vat/exports/{$exportId}/download")->assertOk();
    expect((string) $xmlDownload->getContent())->toContain('CHE-116.281.710 MWST');

    $this->postJson("/api/v1/swiss-vat/returns/{$returnId}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', 'finalized');

    // 7. Cross-tenant isolation: another tenant cannot view tenant A's return
    [$otherTenant] = chVatTenant();
    $this->withHeader('X-Tenant-Id', (string) $otherTenant->id)
        ->getJson("/api/v1/swiss-vat/returns/{$returnId}")
        ->assertNotFound();
});
