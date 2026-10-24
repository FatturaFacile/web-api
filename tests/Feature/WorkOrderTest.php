<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const WO_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    Storage::fake('local');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);

    $this->payload = [
        'client_id' => $this->contact->id,
        'date' => '2026-03-14',
        'started_at' => '2026-03-14 09:00:00',
        'ended_at' => '2026-03-14 11:30:00',
        'intervention_address' => 'Via Roma 1, Milan',
        'lines' => [
            ['type' => 'labor', 'description' => 'On-site repair', 'quantity' => 2.5, 'unit_price' => 80, 'tax_rate' => 22],
            ['type' => 'material', 'description' => 'Replacement pump', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 22],
        ],
    ];
});

function woApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('a work report is created with numbering, technician default and computed totals', function (): void {
    $response = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->assertCreated();

    expect($response->json('data.work_order_number'))->toBe('WO-2026-0001')
        ->and($response->json('data.technician_user_id'))->toBe($this->user->id)
        ->and($response->json('data.status'))->toBe('draft')
        ->and((float) $response->json('data.labor_hours'))->toEqual(2.5)
        ->and((float) $response->json('data.parts_total'))->toEqual(150.0)
        ->and((float) $response->json('data.total_amount'))->toEqual(350.0)
        ->and($response->json('data.lines'))->toHaveCount(2);

    woApi()->getJson('/api/v1/field-service/work-orders/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.client.name', 'Milan Customer SpA');
});

test('work report validation rejects missing lines, unknown line types and reversed times', function (): void {
    woApi()->postJson('/api/v1/field-service/work-orders', array_merge($this->payload, ['lines' => []]))->assertUnprocessable();
    woApi()->postJson('/api/v1/field-service/work-orders', array_merge($this->payload, ['lines' => [['type' => 'magic', 'description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.type');
    woApi()->postJson('/api/v1/field-service/work-orders', array_merge($this->payload, ['ended_at' => '2026-03-14 08:00:00']))
        ->assertUnprocessable()->assertJsonValidationErrors('ended_at');
});

test('a draft work report can be edited and its totals follow the new lines', function (): void {
    $id = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->json('data.id');

    $updated = woApi()->putJson("/api/v1/field-service/work-orders/{$id}", [
        'lines' => [['type' => 'labor', 'description' => 'Follow-up', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertOk();

    expect($updated->json('data.lines'))->toHaveCount(1)->and((float) $updated->json('data.total_amount'))->toEqual(100.0);
});

test('the customer signature is stored privately once and locks the report', function (): void {
    $id = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->json('data.id');

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => base64_encode('not a png')])
        ->assertUnprocessable()->assertJsonValidationErrors('signature_base64');

    $signed = woApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => 'data:image/png;base64,'.WO_PNG])->assertOk();

    expect($signed->json('data.status'))->toBe('signed')
        ->and($signed->json('data.has_signature'))->toBeTrue()
        ->and($signed->json('data'))->not->toHaveKey('client_signature_path');
    Storage::disk('local')->assertExists("tenants/{$this->tenant->id}/signatures/work-orders/{$id}.png");

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => WO_PNG])->assertUnprocessable();
    woApi()->putJson("/api/v1/field-service/work-orders/{$id}", ['notes' => 'late change'])->assertUnprocessable();
});

test('a signed work report is invoiced from its lines exactly once', function (): void {
    $id = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->json('data.id');

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/invoice")->assertUnprocessable(); // draft

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => WO_PNG])->assertOk();
    $invoice = woApi()->postJson("/api/v1/field-service/work-orders/{$id}/invoice")->assertCreated();

    expect((float) $invoice->json('data.total_net'))->toEqual(350.0)
        ->and((float) $invoice->json('data.total_tax'))->toEqual(77.0)
        ->and((float) $invoice->json('data.total_gross'))->toEqual(427.0)
        ->and(woApi()->getJson("/api/v1/field-service/work-orders/{$id}")->json('data.status'))->toBe('invoiced')
        ->and(woApi()->getJson("/api/v1/field-service/work-orders/{$id}")->json('data.invoice_id'))->toBe($invoice->json('data.id'));

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/invoice")->assertUnprocessable();
});

test('signing invoices automatically when the tenant enabled auto invoicing', function (): void {
    woApi()->putJson('/api/v1/settings/preferences/work_orders', ['auto_invoice' => true])->assertOk();
    $id = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->json('data.id');

    $signed = woApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => WO_PNG])->assertOk();

    expect($signed->json('data.status'))->toBe('invoiced')->and(Invoice::count())->toBe(1);
});

test('a completed work report cannot be completed again and the legacy listing still works', function (): void {
    $id = woApi()->postJson('/api/v1/field-service/work-orders', $this->payload)->json('data.id');

    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    woApi()->postJson("/api/v1/field-service/work-orders/{$id}/complete")->assertUnprocessable();
    woApi()->getJson('/api/v1/field-service/work-orders')->assertOk();
});
