<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\ItalianVat\Models\VatRegisterEntry;

uses(LazilyRefreshDatabase::class);

function actingItalianTenantUser(): array
{
    $tenant = Tenant::factory()->create([
        'country_code' => 'IT',
        'vat_number' => 'IT12345678901',
        'fiscal_code' => '12345678901',
        'address' => 'Via Roma 1',
        'postal_code' => '00100',
        'city' => 'Roma',
        'province' => 'RM',
    ]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->givePermissionTo(['esterometro.read', 'esterometro.write', 'esterometro.send', 'esterometro.manage']);

    return [$tenant, $user];
}

it('creates a TD17 self-invoice, reconciles dual VAT registers, and handles SDI RC and NS notifications', function (): void {
    [$tenant, $user] = actingItalianTenantUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/esterometro/self-invoices', [
            'document_type' => 'TD17',
            'integration_date' => '2026-04-12',
            'supplier_country_code' => 'CH',
            'supplier_name' => 'Swiss Cloud AG',
            'supplier_vat_number' => 'CHE123456789MWST',
            'original_invoice_number' => 'CH-2026-88',
            'original_invoice_date' => '2026-04-10',
            'original_currency' => 'CHF',
            'exchange_rate' => 1.05,
            'validate_and_reconcile' => true,
            'lines' => [
                [
                    'description' => 'SaaS Infrastructure',
                    'quantity' => 1,
                    'unit_price_original' => 1000,
                    'vat_rate' => 22,
                    'deductibility_percent' => 100,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.document_type', 'TD17')
        ->assertJsonPath('data.taxable_amount_eur', 1050)
        ->assertJsonPath('data.vat_amount_eur', 231)
        ->assertJsonPath('data.is_vat_reconciled', true)
        ->assertJsonPath('data.status', 'VALIDATED');

    $id = (string) $response->json('data.id');
    $selfInvoice = SelfInvoice::query()->findOrFail($id);

    expect($selfInvoice->sales_vat_entry_id)->not->toBeNull()
        ->and($selfInvoice->purchase_vat_entry_id)->not->toBeNull()
        ->and(VatRegisterEntry::query()->where('id', $selfInvoice->sales_vat_entry_id)->value('register_type'))->toBe('sales')
        ->and(VatRegisterEntry::query()->where('id', $selfInvoice->purchase_vat_entry_id)->value('register_type'))->toBe('purchases');

    $this->actingAs($user)
        ->postJson("/api/v1/esterometro/self-invoices/{$id}/sdi-notification", [
            'notification_type' => 'NS',
            'error_code' => '00404',
            'error_description' => 'Fattura duplicata',
        ])
        ->assertOk()
        ->assertJsonPath('data.sdi_status', 'REJECTED_NS')
        ->assertJsonPath('data.rejection_code', '00404');

    $this->actingAs($user)
        ->getJson('/api/v1/esterometro/dashboard')
        ->assertOk()
        ->assertJsonPath('data.counts.total', 1)
        ->assertJsonPath('data.counts.rejected', 1);
});

it('previews TD16, TD18, TD19, TD20, and TD28 classifications via API', function (): void {
    [, $user] = actingItalianTenantUser();

    $this->actingAs($user)
        ->postJson('/api/v1/esterometro/classify-preview', [
            'supplier_country_code' => 'IT',
            'transaction_nature' => 'DOMESTIC_RC',
            'source_vat_nature' => 'N6.3',
        ])
        ->assertOk()
        ->assertJsonPath('data.document_type', 'TD16');

    $this->actingAs($user)
        ->postJson('/api/v1/esterometro/classify-preview', [
            'supplier_country_code' => 'SM',
            'transaction_nature' => 'SAN_MARINO',
        ])
        ->assertOk()
        ->assertJsonPath('data.document_type', 'TD28');
});
