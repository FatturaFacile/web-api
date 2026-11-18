<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\Warehouse as InventoryWarehouse;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersCommand;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersHandler;
use Modules\ServiceManagement\FieldService\ServiceReportSeal;
use Modules\ServiceManagement\Mail\ServiceReportSignedMail;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

uses(RefreshDatabase::class);

const FSM_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0L10 10" stroke="black"/></svg>';

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();

    // The PDF engine is replaced by the rendered HTML so the tests can read the localized content.
    app()->instance(PdfGenerator::class, new class implements PdfGenerator
    {
        public function render(string $view, array $data = []): string
        {
            return view($view, $data)->render();
        }
    });

    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Dispatcher', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'customer@example.com']);
    $this->technicianUser = User::create(['name' => 'Tech', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->technician = ServiceTechnician::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->technicianUser->id,
        'skills' => ['HVAC', ['name' => 'Electrical']],
        'is_available' => true,
        'hourly_billable_rate' => '100.00',
    ]);
    // The factory is bound to the Inventory module model; the stock service works on the shared App model of the same table.
    $this->van = Warehouse::findOrFail(InventoryWarehouse::factory()->create(['tenant_id' => $this->tenant->id, 'warehouse_type' => 'vehicle_van', 'assigned_user_id' => $this->technicianUser->id, 'is_active' => true])->id);
    $this->part = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'FILTER-1', 'name' => 'Air filter', 'cost_price' => '10.00', 'sale_price' => '30.00', 'tax_rate' => '8.1', 'track_inventory' => true, 'is_active' => true]);
    app(StockService::class)->record((string) $this->part->id, 'in', 10.0, null, $this->van);
});

/**
 * @param  array<string, mixed>  $contract
 * @param  array<string, mixed>  $asset
 */
function fsmContractWithAsset(array $contract = [], array $asset = []): array
{
    $record = ServiceContract::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'customer_id' => test()->customer->id,
        'contract_number' => 'MC-'.Str::random(5),
        'title' => 'Maintenance',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'coverage_type' => 'parts_and_labor',
        'currency' => 'CHF',
        'callout_fee_amount' => '50.00',
        'hourly_labor_rate' => '120.00',
    ], $contract));

    $passport = SerializedEquipmentPassport::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'serial_number' => 'SN-'.Str::random(6),
        'current_owner_customer_id' => test()->customer->id,
        'delivery_date' => '2025-01-01',
        'warranty_policy_type' => 'statutory_ch_24m',
        'warranty_expiry_date' => '2027-01-01',
        'service_contract_id' => $record->id,
        'site_address' => 'Bahnhofstrasse 1, Zürich',
        'site_latitude' => 47.37,
        'site_longitude' => 8.54,
    ], $asset));

    return [$record, $passport];
}

function fsmRunEngine(string $asOf): array
{
    return app(GeneratePreventiveMaintenanceWorkOrdersHandler::class)(new GeneratePreventiveMaintenanceWorkOrdersCommand((string) test()->tenant->id, CarbonImmutable::parse($asOf)));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fsmDispatchedWorkOrder(array $overrides = []): WorkOrder
{
    $workOrder = WorkOrder::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'work_order_number' => 'WO-'.Str::random(8),
        'title' => 'Repair chiller',
        'work_type' => 'corrective',
        'priority' => 'high',
        'status' => 'dispatched',
        'customer_id' => test()->customer->id,
        'assigned_technician_id' => test()->technician->id,
        'scheduled_start' => '2026-03-02 08:00:00',
        'scheduled_end' => '2026-03-02 12:00:00',
        'currency' => 'CHF',
    ], $overrides));

    return $workOrder;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fsmReportPayload(array $overrides = []): array
{
    return array_merge([
        'client_uuid' => 'report-'.Str::random(8),
        'work_performed_summary' => 'Replaced the filter and tested the unit.',
        'checklist' => [['item' => 'visual_inspection', 'result' => 'pass', 'notes' => null], ['item' => 'safety_devices', 'result' => 'fail', 'notes' => 'Replace valve']],
        'labor_hours' => 2,
        'travel_hours' => 1,
        'travel_km' => 35,
        'apply_callout_fee' => true,
        'customer_signer_name' => 'Anna Muster',
        'customer_signer_role' => 'Facility manager',
        'signature_svg' => FSM_SVG,
        'signed_at' => '2026-03-02T11:30:00Z',
        'gps_latitude' => 47.37,
        'gps_longitude' => 8.54,
        'locale' => 'it-CH',
    ], $overrides);
}

test('the preventive engine creates a calendar work order once per cycle', function (): void {
    [, $asset] = fsmContractWithAsset(['preventive_frequency_rule' => ['calendar' => ['every' => 6, 'unit' => 'month'], 'lead_days' => 14]]);

    expect(fsmRunEngine('2026-03-01')['created'])->toBe([]);

    $first = fsmRunEngine('2026-06-20');
    expect($first['created'])->toHaveCount(1);

    $second = fsmRunEngine('2026-06-25');
    expect($second['created'])->toBe([])->and($second['skipped_existing'])->toBe(1);

    $workOrder = WorkOrder::findOrFail($first['created'][0]);
    expect($workOrder->work_type)->toBe('preventive')
        ->and($workOrder->status)->toBe('unscheduled')
        ->and($workOrder->equipment_passport_id)->toBe($asset->id)
        ->and($asset->refresh()->next_scheduled_service_date->toDateString())->toBe('2026-07-01');
});

test('the preventive engine reacts to operating-hours and cycle thresholds and is idempotent', function (): void {
    [, $asset] = fsmContractWithAsset(['preventive_frequency_rule' => ['meter' => ['operating_hours' => 500, 'cycles' => 10000, 'lead_percent' => 10]]], ['current_operating_hours' => 300, 'current_cycle_count' => 2000]);

    expect(fsmRunEngine('2026-02-01')['created'])->toBe([]);

    $asset->update(['current_operating_hours' => 455]);
    expect(fsmRunEngine('2026-02-01')['created'])->toHaveCount(1)
        ->and(fsmRunEngine('2026-02-02')['created'])->toBe([]);

    $asset->update(['current_cycle_count' => 9100]);
    expect(fsmRunEngine('2026-02-03')['created'])->toHaveCount(1)
        ->and(WorkOrder::where('preventive_cycle_key', 'like', "hrs:{$asset->id}:%")->count())->toBe(1)
        ->and(WorkOrder::where('preventive_cycle_key', 'like', "cyc:{$asset->id}:%")->count())->toBe(1);
});

test('dispatch is blocked by skills, schedule overlap and missing van stock, and reports every blocker', function (): void {
    fsmDispatchedWorkOrder(['scheduled_start' => '2026-03-02 09:00:00', 'scheduled_end' => '2026-03-02 11:00:00']);
    $target = fsmDispatchedWorkOrder([
        'status' => 'unscheduled',
        'assigned_technician_id' => null,
        'scheduled_start' => null,
        'scheduled_end' => null,
        'required_skills' => ['Welding'],
        'required_parts' => [['product_id' => (string) $this->part->id, 'quantity' => 50]],
    ]);

    $this->postJson("/api/v1/fsm/work-orders/{$target->id}/dispatch", ['technician_id' => $this->technician->id, 'scheduled_start' => '2026-03-02T10:00:00Z', 'scheduled_end' => '2026-03-02T12:00:00Z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['skills', 'schedule', 'van_stock']);

    $target->update(['required_skills' => ['hvac', 'ELECTRICAL'], 'required_parts' => [['product_id' => (string) $this->part->id, 'quantity' => 4]]]);

    $this->postJson("/api/v1/fsm/work-orders/{$target->id}/dispatch", ['technician_id' => $this->technician->id, 'scheduled_start' => '2026-03-02T13:00:00Z', 'scheduled_end' => '2026-03-02T15:00:00Z'])
        ->assertOk()
        ->assertJsonPath('data.status', 'dispatched')
        ->assertJsonPath('data.assigned_technician_id', $this->technician->id);
});

test('van stock consumption deducts the van, separates covered from billable lines and is idempotent', function (): void {
    [$contract] = fsmContractWithAsset(['coverage_type' => 'labor_only']);
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id]);
    $payload = ['client_uuid' => 'part-1', 'product_id' => (string) $this->part->id, 'quantity' => 2, 'unit_price' => '30.00'];

    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", $payload)->assertCreated()->assertJsonPath('data.covered', false)->assertJsonPath('data.unit_price', '30.00');
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", $payload)->assertOk();

    expect(PartsConsumption::where('work_order_id', $workOrder->id)->count())->toBe(1)
        ->and(app(StockService::class)->levels((string) $this->van->id)->firstWhere('product_id', (string) $this->part->id)['on_hand'])->toBe(8.0);

    $contract->update(['coverage_type' => 'parts_and_labor']);
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", ['client_uuid' => 'part-2'] + $payload)->assertCreated()->assertJsonPath('data.covered', true)->assertJsonPath('data.unit_price', '0.00');
});

test('parts cannot be taken when the technician has no van', function (): void {
    $this->van->forceFill(['is_active' => false])->save();
    $workOrder = fsmDispatchedWorkOrder();

    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", ['product_id' => (string) $this->part->id, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('van_warehouse');
});

test('signing a report seals it, completes the work order, advances meters, renders localized PDFs and queues the e-mail', function (string $locale, string $title): void {
    [$contract, $asset] = fsmContractWithAsset(['coverage_type' => 'labor_only', 'preventive_frequency_rule' => ['calendar' => ['every' => 6, 'unit' => 'month']]], ['current_operating_hours' => 400]);
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id, 'equipment_passport_id' => $asset->id, 'status' => 'on_site']);
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", ['product_id' => (string) $this->part->id, 'quantity' => 1, 'unit_price' => '30.00'])->assertCreated();

    $response = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", fsmReportPayload(['locale' => $locale, 'meter_reading_hours' => 450.5, 'meter_reading_cycles' => 1200]))
        ->assertCreated()
        ->assertJsonPath('data.signature_intact', true);

    $report = DigitalServiceReport::findOrFail($response->json('data.id'));
    expect(ServiceReportSeal::verify($report))->toBeTrue()
        ->and($report->signature_sha256_hash)->toHaveLength(64)
        ->and($workOrder->refresh()->status)->toBe('completed')
        ->and($workOrder->customer_signed)->toBeTrue()
        ->and($asset->refresh()->current_operating_hours)->toBe('450.50')
        ->and($asset->last_service_date->toDateString())->toBe('2026-03-02')
        ->and($asset->next_scheduled_service_date->toDateString())->toBe('2026-09-02')
        ->and($contract->refresh()->used_visits_current_year)->toBe(1);

    $html = Storage::disk('local')->get($report->pdf_path);
    expect($html)->toContain(e($title))->toContain($report->report_number)->toContain('Anna Muster');

    Mail::assertQueued(ServiceReportSignedMail::class, fn (ServiceReportSignedMail $m): bool => $m->hasTo('customer@example.com') && $m->reportLocale === $locale);

    // The seal detects tampering.
    $report->update(['work_performed_summary' => 'Altered afterwards']);
    expect(ServiceReportSeal::verify($report->refresh()))->toBeFalse();
})->with([
    'it-CH' => ['it-CH', "Rapportino d'Intervento"],
    'de-CH' => ['de-CH', 'Servicerapport / Arbeitsrapport'],
    'fr-CH' => ['fr-CH', "Rapport d'intervention"],
]);

test('the signed report lists covered items at 0.00 and billable items apart, with the callout fee', function (): void {
    [$contract] = fsmContractWithAsset(['coverage_type' => 'labor_only']);
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id]);
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", ['product_id' => (string) $this->part->id, 'quantity' => 1, 'unit_price' => '30.00'])->assertCreated();

    $billing = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", fsmReportPayload())->assertCreated()->json('data.billing');

    $byKind = collect($billing['lines'])->keyBy('kind');
    expect($byKind['labor']['charged_amount'])->toBe('0.00')->and($byKind['labor']['covered'])->toBeTrue()
        ->and($byKind['travel']['charged_amount'])->toBe('120.00')
        ->and($byKind['callout']['charged_amount'])->toBe('50.00')
        ->and($byKind['part']['charged_amount'])->toBe('30.00')
        ->and($billing['billable_total'])->toBe('200.00')
        ->and($billing['covered_total'])->toBe('240.00');
});

test('a replayed sign request returns the original report without side effects, a second report is refused', function (): void {
    [$contract, $asset] = fsmContractWithAsset();
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id, 'equipment_passport_id' => $asset->id]);
    $payload = fsmReportPayload(['client_uuid' => 'sync-1', 'meter_reading_hours' => 10]);

    $first = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", $payload)->assertCreated();
    $replay = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", $payload)->assertOk();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DigitalServiceReport::count())->toBe(1)
        ->and($contract->refresh()->used_visits_current_year)->toBe(1);
    Mail::assertQueued(ServiceReportSignedMail::class, 1);

    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", ['client_uuid' => 'other'] + $payload)->assertUnprocessable();
});

test('signature and meter input is validated', function (): void {
    [, $asset] = fsmContractWithAsset([], ['current_operating_hours' => 500]);
    $workOrder = fsmDispatchedWorkOrder(['equipment_passport_id' => $asset->id]);
    $url = "/api/v1/fsm/work-orders/{$workOrder->id}/service-report";

    $this->postJson($url, fsmReportPayload(['signature_svg' => '<svg><script>alert(1)</script></svg>']))->assertUnprocessable()->assertJsonValidationErrors('signature_svg');
    $this->postJson($url, fsmReportPayload(['meter_reading_hours' => 100]))->assertUnprocessable()->assertJsonValidationErrors('meter_reading');
    expect(DigitalServiceReport::count())->toBe(0);
});

test('a report converts to an invoice holding only the billable lines, once', function (): void {
    [$contract] = fsmContractWithAsset(['coverage_type' => 'labor_only']);
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id]);
    $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/parts", ['product_id' => (string) $this->part->id, 'quantity' => 1, 'unit_price' => '30.00'])->assertCreated();
    $reportId = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", fsmReportPayload())->assertCreated()->json('data.id');

    $invoiceId = $this->postJson("/api/v1/fsm/service-reports/{$reportId}/invoice", ['tax_rate' => 8.1])->assertCreated()->json('data.invoice_id');

    $lines = SalesInvoice::findOrFail($invoiceId)->items()->get();
    expect($lines)->toHaveCount(3)
        ->and($workOrder->refresh()->status)->toBe('invoiced')
        ->and($workOrder->invoice_id)->toBe($invoiceId)
        ->and(DigitalServiceReport::findOrFail($reportId)->invoice_id)->toBe($invoiceId);

    $this->postJson("/api/v1/fsm/service-reports/{$reportId}/invoice", ['tax_rate' => 8.1])->assertUnprocessable();
});

test('nothing is invoiced when the contract covers everything', function (): void {
    [$contract] = fsmContractWithAsset(['coverage_type' => 'full_comprehensive']);
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id]);
    $reportId = $this->postJson("/api/v1/fsm/work-orders/{$workOrder->id}/service-report", fsmReportPayload())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/fsm/service-reports/{$reportId}/invoice", ['tax_rate' => 8.1])->assertUnprocessable();
    expect($workOrder->refresh()->status)->toBe('completed');
});

test('the mobile sync applies operations in order and reports duplicates and rejections', function (): void {
    $workOrder = fsmDispatchedWorkOrder();
    $operations = [
        ['client_uuid' => 'op-1', 'type' => 'status_transition', 'work_order_id' => $workOrder->id, 'payload' => ['status' => 'en_route']],
        ['client_uuid' => 'op-2', 'type' => 'status_transition', 'work_order_id' => $workOrder->id, 'payload' => ['status' => 'on_site', 'at' => '2026-03-02T08:30:00Z']],
        ['client_uuid' => 'op-3', 'type' => 'part_consumption', 'work_order_id' => $workOrder->id, 'payload' => ['product_id' => (string) $this->part->id, 'quantity' => 1, 'unit_price' => '30']],
        ['client_uuid' => 'op-4', 'type' => 'service_report', 'work_order_id' => $workOrder->id, 'payload' => Arr::except(fsmReportPayload(), ['client_uuid'])],
        ['client_uuid' => 'op-5', 'type' => 'part_consumption', 'work_order_id' => $workOrder->id, 'payload' => ['product_id' => (string) $this->part->id, 'quantity' => 1]],
    ];

    $first = $this->postJson('/api/v1/fsm/mobile-sync', ['operations' => $operations])->assertOk()->json('data.results');
    expect(collect($first)->pluck('status')->all())->toBe(['applied', 'applied', 'applied', 'applied', 'rejected']);

    $replay = $this->postJson('/api/v1/fsm/mobile-sync', ['operations' => array_slice($operations, 0, 4)])->assertOk()->json('data.results');
    expect(collect($replay)->pluck('status')->all())->toBe(['duplicate', 'duplicate', 'duplicate', 'duplicate'])
        ->and(DigitalServiceReport::count())->toBe(1)
        ->and(PartsConsumption::count())->toBe(1);
});

test('the mobile sync never touches work orders of another tenant', function (): void {
    $foreign = Tenant::query()->whereKey($this->other->id)->first();
    $workOrder = app(TenantContext::class)->runWithTenant($foreign, fn (): WorkOrder => WorkOrder::create(['tenant_id' => $foreign->id, 'work_order_number' => 'WO-X', 'title' => 'Other', 'work_type' => 'corrective', 'status' => 'dispatched', 'customer_id' => (string) Str::uuid()]));

    $result = $this->postJson('/api/v1/fsm/mobile-sync', ['operations' => [['client_uuid' => 'x-1', 'type' => 'status_transition', 'work_order_id' => $workOrder->id, 'payload' => ['status' => 'en_route']]]])->assertOk()->json('data.results.0');

    expect($result['status'])->toBe('rejected')->and($workOrder->refresh()->status)->toBe('dispatched');
    $this->getJson("/api/v1/fsm/work-orders/{$workOrder->id}")->assertNotFound();
});

test('the offline bundle carries the work queue, equipment history and the full van catalog', function (): void {
    [$contract, $asset] = fsmContractWithAsset();
    $workOrder = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id, 'equipment_passport_id' => $asset->id]);

    $bundle = $this->getJson("/api/v1/fsm/technicians/{$this->technician->id}/offline-bundle")->assertOk()->json('data');

    expect($bundle['open_work_order_ids'])->toBe([$workOrder->id])
        ->and($bundle['work_orders'][0]['checklist_template'])->not->toBeEmpty()
        ->and($bundle['equipment'][0]['id'])->toBe($asset->id)
        ->and($bundle['customers'][0]['id'])->toBe($this->customer->id)
        ->and($bundle['van_stock'][0]['sku'])->toBe('FILTER-1')
        ->and($bundle['technician']['van_warehouse_id'])->toBe($this->van->id);

    $delta = $this->getJson("/api/v1/fsm/technicians/{$this->technician->id}/offline-bundle?since=".urlencode(now()->addMinute()->toIso8601String()))->assertOk()->json('data');
    expect($delta['work_orders'])->toBe([])->and($delta['open_work_order_ids'])->toBe([$workOrder->id]);
});

test('the dispatcher board lists the queue, technician lanes with blocks, skills and van readiness', function (): void {
    fsmDispatchedWorkOrder();
    fsmDispatchedWorkOrder(['status' => 'unscheduled', 'assigned_technician_id' => null, 'scheduled_start' => null, 'scheduled_end' => null, 'priority' => 'urgent', 'location' => ['address' => 'Zürich', 'lat' => 47.37, 'lng' => 8.54]]);

    $board = $this->getJson('/api/v1/fsm/board?from=2026-03-01T00:00:00Z&to=2026-03-08T00:00:00Z')->assertOk()->json('data');

    expect($board['unassigned'])->toHaveCount(1)->and($board['unassigned'][0]['site']['latitude'])->toBe(47.37)
        ->and($board['lanes'])->toHaveCount(1)
        ->and($board['lanes'][0]['skills'])->toBe(['hvac', 'electrical'])
        ->and($board['lanes'][0]['van']['ready'])->toBeTrue()
        ->and($board['lanes'][0]['blocks'])->toHaveCount(1);
});

test('the equipment service history is chronological with parts and meter progression', function (): void {
    [$contract, $asset] = fsmContractWithAsset();
    $first = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id, 'equipment_passport_id' => $asset->id]);
    $this->postJson("/api/v1/fsm/work-orders/{$first->id}/service-report", fsmReportPayload(['signed_at' => '2026-03-02T11:00:00Z', 'meter_reading_hours' => 100]))->assertCreated();
    $second = fsmDispatchedWorkOrder(['service_contract_id' => $contract->id, 'equipment_passport_id' => $asset->id, 'scheduled_start' => '2026-09-02 08:00:00', 'scheduled_end' => '2026-09-02 10:00:00']);
    $this->postJson("/api/v1/fsm/work-orders/{$second->id}/service-report", fsmReportPayload(['signed_at' => '2026-09-02T11:00:00Z', 'meter_reading_hours' => 600]))->assertCreated();

    $history = $this->getJson("/api/v1/fsm/equipment/{$asset->id}/service-history")->assertOk()->json('data');

    expect(collect($history['history'])->pluck('meter_reading_hours')->all())->toBe(['100.00', '600.00'])
        ->and($history['equipment']['current_operating_hours'])->toBe('600.00');
});

test('maintenance contracts are created and updated with coverage, rule and equipment, and the engine can be run on demand', function (): void {
    [, $asset] = fsmContractWithAsset();
    $asset->update(['service_contract_id' => null]);

    $created = $this->postJson('/api/v1/fsm/maintenance-contracts', [
        'customer_id' => $this->customer->id,
        'contract_number' => 'MC-100',
        'title' => 'Chiller care',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'coverage_type' => 'full_comprehensive',
        'preventive_frequency_rule' => ['calendar' => ['every' => 3, 'unit' => 'month'], 'meter' => ['operating_hours' => 500], 'lead_days' => 7],
        'included_visits_per_year' => 4,
        'callout_fee_amount' => 45,
        'hourly_labor_rate' => 110,
        'equipment_passport_ids' => [$asset->id],
    ])->assertCreated()->assertJsonPath('data.coverage_type', 'full_comprehensive')->assertJsonPath('data.equipment_passport_ids.0', $asset->id);

    $this->postJson('/api/v1/fsm/maintenance-contracts', ['coverage_type' => 'bogus'])->assertUnprocessable();

    $this->patchJson('/api/v1/fsm/maintenance-contracts/'.$created->json('data.id'), ['coverage_type' => 'labor_only', 'status' => 'suspended'])
        ->assertOk()->assertJsonPath('data.coverage_type', 'labor_only')->assertJsonPath('data.status', 'suspended');

    $this->patchJson('/api/v1/fsm/maintenance-contracts/'.$created->json('data.id'), ['status' => 'active'])->assertOk();
    $run = $this->postJson('/api/v1/fsm/preventive-maintenance/run')->assertOk()->json('data');
    expect($run['evaluated_assets'])->toBe(1)->and($run['created'])->toHaveCount(1);
    $this->postJson('/api/v1/fsm/preventive-maintenance/run')->assertOk()->assertJsonPath('data.created', []);
});

test('an en-route and on-site journey is idempotent and refuses invalid jumps', function (): void {
    $workOrder = fsmDispatchedWorkOrder();
    $url = "/api/v1/fsm/work-orders/{$workOrder->id}/status";

    $this->postJson($url, ['status' => 'en_route'])->assertOk()->assertJsonPath('data.status', 'en_route');
    $this->postJson($url, ['status' => 'en_route'])->assertOk();
    $this->postJson($url, ['status' => 'on_site'])->assertOk()->assertJsonPath('data.status', 'on_site');
    $this->postJson($url, ['status' => 'en_route'])->assertUnprocessable();
    expect($workOrder->refresh()->actual_arrival)->not->toBeNull();
});

test('a work order is created idempotently from a client uuid', function (): void {
    $payload = ['client_uuid' => 'wo-1', 'work_order_type' => 'installation', 'customer_id' => $this->customer->id, 'title' => 'Install unit', 'required_skills' => ['HVAC'], 'site_address' => 'Zürich', 'site_latitude' => 47.37, 'site_longitude' => 8.54];

    $a = $this->postJson('/api/v1/fsm/work-orders', $payload)->assertCreated()->assertJsonPath('data.status', 'unscheduled');
    $b = $this->postJson('/api/v1/fsm/work-orders', $payload)->assertOk();

    expect($b->json('data.id'))->toBe($a->json('data.id'))->and(WorkOrder::where('client_uuid', 'wo-1')->count())->toBe(1);
});
