<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('supplier invoices are listed with the stored columns of the document and the short pagination meta', function (): void {
    $tenant = Tenant::create(['name' => 'Supplier SRL', 'legal_name' => 'Supplier SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Admin', 'email' => 'admin@supplier-shape.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $supplier = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'Supplier SpA', 'country' => 'IT']);
    PassiveInvoice::create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'S-1', 'date' => '2026-03-01', 'total_amount' => 100, 'document_type' => 'TD01']);

    $response = $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/purchases/supplier-invoices')->assertOk();

    expect(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total']);

    $row = $response->json('data.0');
    expect($row)->toHaveKeys(['id', 'tenant_id', 'supplier_id', 'invoice_number', 'date', 'due_date', 'total_amount', 'currency', 'is_reconciled', 'document_type', 'total_net', 'total_tax', 'payment_status', 'total_paid', 'withholding_tax_amount', 'withholdings', 'approval_status', 'stamp_duty_amount', 'match_status', 'purchase_order_id'])
        ->and($row)->not->toHaveKey('xml_payload')
        ->and($row['total_amount'])->toBe('100.00')
        ->and($row['is_reconciled'])->toBeFalse();

});
