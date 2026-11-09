<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;

uses(RefreshDatabase::class);

function esmMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Member '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    Sanctum::actingAs(esmMember($this->tenant));
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->technician = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'hourly_cost_rate' => '40.00', 'hourly_billable_rate' => '95.00']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function esmWorkOrder(array $overrides = []): array
{
    return test()->postJson('/api/v1/esm/work-orders', [
        'title' => 'Revisione impianto',
        'customer_id' => test()->customer->id,
        'items' => [
            ['item_type' => 'labor', 'description' => 'Manodopera', 'quantity' => '2.5', 'unit_cost' => '40.00', 'unit_price' => '95.10', 'is_billable' => true],
            ['item_type' => 'part', 'description' => 'Guarnizione', 'quantity' => '3', 'unit_cost' => '0.10', 'unit_price' => '0.10', 'is_billable' => true],
            ['item_type' => 'expense', 'description' => 'Trasferta interna', 'quantity' => '1', 'unit_cost' => '20.00', 'unit_price' => '20.00', 'is_billable' => false],
        ],
        ...$overrides,
    ])->assertCreated()->json('data');
}

test('work order totals are exact decimal strings and a non billable item is not charged', function (): void {
    $data = esmWorkOrder();

    // 2.5 x 95.10 = 237.75 and 3 x 0.10 = 0.30: no float drift, and the 20.00 expense is cost only.
    expect($data['total_billed_amount'])->toBe('238.05')
        ->and($data['total_cost'])->toBe('120.30')
        ->and($data['labor_cost'])->toBe('100.00')
        ->and($data['material_cost'])->toBe('20.30')
        ->and($data['is_invoiced'])->toBeFalse();
});

test('a work order refuses a customer, contract, team or technician of another company', function (): void {
    $foreign = Contact::factory()->create(['tenant_id' => $this->other->id]);

    $this->postJson('/api/v1/esm/work-orders', [
        'title' => 'Intervento',
        'customer_id' => $foreign->id,
        'service_contract_id' => (string) Str::uuid(),
        'service_team_id' => (string) Str::uuid(),
        'lead_technician_id' => ServiceTechnician::factory()->create(['tenant_id' => $this->other->id])->id,
        'project_id' => (string) Str::uuid(),
        'asset_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'service_contract_id', 'service_team_id', 'lead_technician_id', 'project_id', 'asset_id']);

    expect(WorkOrder::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('work order items are validated', function (): void {
    $this->postJson('/api/v1/esm/work-orders', ['title' => 'X', 'items' => [['quantity' => '-1', 'unit_price' => 'abc']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_price']);
});

test('dispatch and schedule refuse the technician of another company', function (): void {
    $workOrder = esmWorkOrder();
    $foreignTechnician = ServiceTechnician::factory()->create(['tenant_id' => $this->other->id]);

    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/dispatch", ['technician_id' => $foreignTechnician->id])
        ->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/schedule", ['technician_id' => $foreignTechnician->id, 'scheduled_start' => '2027-05-03 09:00:00', 'scheduled_end' => '2027-05-03 11:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/dispatch", ['technician_id' => $this->technician->id])->assertOk();
});

test('a visit needs a real technician instead of an invented one', function (): void {
    $workOrder = esmWorkOrder();
    $before = ServiceTechnician::query()->count();

    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/record-visit", ['work_summary' => 'Controllo'])
        ->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/record-visit", ['technician_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('technician_id');

    $visit = $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/record-visit", ['technician_id' => $this->technician->id, 'work_summary' => 'Controllo eseguito'])
        ->assertOk()->json('data');

    expect($visit['technician_id'])->toBe($this->technician->id)
        ->and(ServiceTechnician::query()->count())->toBe($before)
        ->and(WorkOrder::query()->findOrFail($workOrder['id'])->status)->toBe('in_progress');
});

test('a part is a real product: unknown SKU and closed work orders are refused, totals stay exact', function (): void {
    $workOrder = esmWorkOrder();
    Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BRUSH-88', 'name' => 'Spazzole', 'cost_price' => '15.10', 'sale_price' => '35.20', 'tax_rate' => '22', 'track_inventory' => false, 'is_active' => true]);
    Product::create(['tenant_id' => $this->other->id, 'sku' => 'FOREIGN-1', 'name' => 'Altrui', 'cost_price' => '1', 'sale_price' => '2', 'tax_rate' => '22', 'track_inventory' => false, 'is_active' => true]);
    $url = "/api/v1/esm/work-orders/{$workOrder['id']}/parts";

    // A draft work order is not open for parts yet.
    $this->postJson($url, ['item_number' => 'BRUSH-88', 'quantity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('work_order');

    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/dispatch", ['technician_id' => $this->technician->id])->assertOk();

    $this->postJson($url, ['item_number' => 'NOPE', 'quantity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('item_number');
    $this->postJson($url, ['item_number' => 'FOREIGN-1', 'quantity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('item_number');
    $this->postJson($url, ['item_number' => 'BRUSH-88', 'quantity' => '0'])->assertUnprocessable()->assertJsonValidationErrors('quantity');

    $data = $this->postJson($url, ['item_number' => 'BRUSH-88', 'quantity' => '3'])->assertCreated()->json('data');

    // 3 x 35.20 = 105.60 billable and 3 x 15.10 = 45.30 cost, on top of 238.05 and 120.30.
    expect($data['total_billed_amount'])->toBe('343.65')
        ->and($data['total_cost'])->toBe('165.60')
        ->and($data['material_cost'])->toBe('65.60');
});

test('a completed work order is invoiced once through the invoicing service', function (): void {
    $workOrder = esmWorkOrder();
    $url = "/api/v1/esm/work-orders/{$workOrder['id']}/invoice";

    $this->postJson($url, ['tax_rate' => '22'])->assertUnprocessable()->assertJsonValidationErrors('work_order');
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');

    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/complete", ['resolution_summary' => 'Fatto'])->assertOk();

    $invoice = $this->postJson($url, ['tax_rate' => '22', 'due_date' => '2027-06-30'])->assertCreated()->json('data');

    // Net 238.05, VAT 22% = 52.37 (52.371 rounded), gross 290.42.
    $saved = SalesInvoice::query()->with('items')->findOrFail($invoice['invoice_id']);
    expect($saved->contact_id)->toBe($this->customer->id)
        ->and($saved->items)->toHaveCount(2)
        ->and((string) $saved->total_net)->toBe('238.05')
        ->and($invoice['total_gross'])->toBe((string) $saved->total_gross)
        ->and(WorkOrder::query()->findOrFail($workOrder['id']))->is_invoiced->toBeTrue()->invoice_id->toBe($invoice['invoice_id']);

    $this->postJson($url, ['tax_rate' => '22'])->assertUnprocessable()->assertJsonValidationErrors('work_order');
    expect(SalesInvoice::query()->count())->toBe(1);
});

test('an internal work order cannot be invoiced', function (): void {
    $workOrder = esmWorkOrder(['customer_id' => null]);
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/complete")->assertOk();

    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/invoice", ['tax_rate' => '22'])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
});

test('work order invoice, parts and visit routes answer 404 across tenants and 403 to a read-only role', function (): void {
    $foreign = WorkOrder::factory()->create(['tenant_id' => $this->other->id]);

    foreach (['invoice' => ['tax_rate' => '22'], 'parts' => ['item_number' => 'X', 'quantity' => '1'], 'complete' => [], 'dispatch' => []] as $route => $payload) {
        $this->postJson("/api/v1/esm/work-orders/{$foreign->id}/{$route}", $payload)->assertNotFound();
    }

    $workOrder = esmWorkOrder();
    Sanctum::actingAs(esmMember($this->tenant, 'accountant_readonly'));
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/invoice", ['tax_rate' => '22'])->assertForbidden();
    $this->postJson("/api/v1/esm/work-orders/{$workOrder['id']}/parts", ['item_number' => 'X', 'quantity' => '1'])->assertForbidden();
    $this->getJson("/api/v1/esm/work-orders/{$workOrder['id']}")->assertOk();
});

test('logging time bills exact cents from the technician rates', function (): void {
    $ticket = $this->postJson('/api/v1/esm/tickets', ['title' => 'Guasto'])->assertCreated()->json('data');
    $entry = app(TicketServiceInterface::class)->logTime($ticket['id'], '1.25', $this->technician->id, 'Diagnosi');

    // 1.25 h x 95.00 = 118.75 charged and 1.25 h x 40.00 = 50.00 cost, 75 minutes.
    expect((string) $entry->total_charge)->toBe('118.75')
        ->and((string) $entry->total_cost)->toBe('50.00')
        ->and(Ticket::query()->findOrFail($ticket['id'])->actual_resolution_minutes)->toBe(75);
});

test('change requests and problems are listed per tenant and the incident references are tenant checked', function (): void {
    $this->postJson('/api/v1/esm/changes', ['title' => 'Aggiornamento firewall', 'change_type' => 'normal', 'risk_level' => 'low'])->assertCreated();
    $this->postJson('/api/v1/esm/changes', ['title' => 'X', 'service_category_id' => (string) Str::uuid(), 'rollout_plan' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('service_category_id');
    $this->postJson('/api/v1/esm/incidents', ['title' => 'Down', 'affected_asset_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('affected_asset_id');

    expect($this->getJson('/api/v1/esm/changes')->assertOk()->json('meta.total'))->toBe(1);
    $this->getJson('/api/v1/esm/problems')->assertOk();

    Sanctum::actingAs(esmMember($this->other));
    $this->withHeader('X-Tenant-Id', $this->other->id);
    expect($this->getJson('/api/v1/esm/changes')->assertOk()->json('meta.total'))->toBe(0);
});

test('service contracts are listed and shown per tenant with the value as a decimal string', function (): void {
    $mine = ServiceContract::factory()->create(['tenant_id' => $this->tenant->id, 'annual_value' => '12000.50', 'status' => 'active']);
    $theirs = ServiceContract::factory()->create(['tenant_id' => $this->other->id]);

    expect($this->getJson('/api/v1/esm/service-contracts')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/esm/service-contracts/{$mine->id}")->assertOk()->json('data.contract_value'))->toBe('12000.50');
    $this->getJson("/api/v1/esm/service-contracts/{$theirs->id}")->assertNotFound();
    $this->getJson('/api/v1/esm/service-contracts?status=expired')->assertOk()->assertJsonCount(0, 'data');
});

test('the priority matrix of the helpdesk is readable', function (): void {
    $this->getJson('/api/v1/esm/helpdesk/priority-matrix')->assertOk();
});
