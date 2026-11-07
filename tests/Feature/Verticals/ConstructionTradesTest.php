<?php

declare(strict_types=1);

use App\Models\ConstructionSite;
use App\Models\Invoice;
use App\Models\ProgressBilling;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    $this->priceList = fn (): string => $this->postJson('/api/v1/construction-trades/price-lists', ['name' => 'Listino pittore', 'source' => 'own', 'currency' => 'CHF', 'languages' => ['it', 'de']])
        ->assertCreated()->json('data.id');

    $this->acceptedBid = function (array $overrides = []): array {
        $listId = ($this->priceList)();
        $itemId = $this->postJson("/api/v1/construction-trades/price-lists/{$listId}/items", [
            'position_code' => '211.100', 'unit' => 'm2', 'unit_price' => '25', 'labour_minutes' => '20', 'material_cost' => '3', 'machine_cost' => '0.5',
            'descriptions' => ['it' => 'Tinteggiatura pareti', 'de' => 'Wände streichen'],
        ])->assertCreated()->json('data.id');
        $cycleId = $this->postJson('/api/v1/construction-trades/work-cycles', [
            'name' => 'Facciata', 'unit' => 'm2', 'descriptions' => ['it' => 'Ciclo facciata'],
            'steps' => [
                ['descriptions' => ['it' => 'Fondo'], 'unit_price' => '10', 'labour_minutes' => '10', 'material_cost' => '2'],
                ['descriptions' => ['it' => 'Due mani'], 'quantity_factor' => '2', 'unit_price' => '6', 'labour_minutes' => '5', 'material_cost' => '1'],
            ],
        ])->assertCreated()->json('data.id');

        $bidId = $this->postJson('/api/v1/construction-trades/bids', [
            'contact_id' => $this->customer->id, 'title' => 'Casa Rossi', 'language' => 'de', 'currency' => 'CHF', 'tax_rate' => '8.1',
            'retention_percentage' => '10', 'labour_hour_rate' => '60', ...$overrides,
        ])->assertCreated()->json('data.id');

        $walls = $this->postJson("/api/v1/construction-trades/bids/{$bidId}/positions", ['price_item_id' => $itemId, 'quantity' => '100'])->assertCreated()
            ->assertJsonPath('data.description', 'Wände streichen')
            ->assertJsonPath('data.total', '2500.00')
            ->assertJsonPath('data.budget_labour_amount', '2000.00')
            ->assertJsonPath('data.budget_material_amount', '300.00');
        $facade = $this->postJson("/api/v1/construction-trades/bids/{$bidId}/positions", ['work_cycle_id' => $cycleId, 'position_code' => '300', 'quantity' => '50'])->assertCreated()
            ->assertJsonPath('data.unit_price', '22.0000')
            ->assertJsonPath('data.total', '1100.00')
            ->assertJsonPath('data.budget_material_amount', '200.00');

        $this->postJson("/api/v1/construction-trades/bids/{$bidId}/status", ['status' => 'submitted'])->assertOk();
        $this->postJson("/api/v1/construction-trades/bids/{$bidId}/status", ['status' => 'accepted'])->assertOk();

        return ['bid' => $bidId, 'walls' => $walls->json('data.id'), 'facade' => $facade->json('data.id'), 'item' => $itemId];
    };
});

test('a bid is billed partially, settled and closed by a final account that releases the retentions', function (): void {
    verticalLedger($this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);
    $site = ConstructionSite::create(['client_id' => $this->customer->id, 'code' => 'C-1', 'name' => 'Casa Rossi', 'address' => 'Via Nassa 1']);
    verticalApi($this->user, $this->tenant);
    $ids = ($this->acceptedBid)(['construction_site_id' => $site->id]);
    $bid = $ids['bid'];

    $this->getJson("/api/v1/construction-trades/bids/{$bid}")->assertJsonPath('meta.net_total', '3600.00');
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/positions", ['position_code' => 'X', 'description' => 'x', 'unit' => 'pz', 'quantity' => '1', 'unit_price' => '1'])->assertUnprocessable();

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'final', 'date' => '2026-11-07'])->assertUnprocessable();
    $first = $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'partial', 'basis' => 'percentage', 'percentage' => '30', 'date' => '2026-11-07'])->assertCreated()
        ->assertJsonPath('data.gross_this', '1080.00')->assertJsonPath('data.retention_amount', '108.00')->assertJsonPath('data.amount_invoiced', '972.00');
    expect((float) Invoice::query()->findOrFail($first->json('data.invoice_id'))->total_net)->toBe(972.0);

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/measurements", ['bid_position_id' => $ids['walls'], 'measured_on' => '2026-11-07', 'quantity' => '60'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/measurements", ['bid_position_id' => $ids['facade'], 'measured_on' => '2026-11-07', 'quantity' => '50'])->assertCreated();
    $this->getJson("/api/v1/construction-trades/bids/{$bid}/settlement")->assertJsonPath('data.net', '2600.00');

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'partial', 'basis' => 'measured', 'date' => '2026-11-07'])->assertCreated()
        ->assertJsonPath('data.gross_this', '1520.00')->assertJsonPath('data.amount_invoiced', '1368.00');
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'partial', 'basis' => 'measured', 'date' => '2026-11-07'])->assertUnprocessable();

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/settlement/approve")->assertOk();
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/measurements", ['bid_position_id' => $ids['walls'], 'measured_on' => '2026-11-07', 'quantity' => '1'])->assertUnprocessable();

    $final = $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'final', 'date' => '2026-11-07'])->assertCreated()->assertJsonPath('data.amount_invoiced', '260.00');
    expect((float) Invoice::query()->findOrFail($final->json('data.invoice_id'))->total_net)->toBe(260.0)
        ->and(ProgressBilling::query()->where('construction_site_id', $site->id)->count())->toBe(3);

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/billings", ['kind' => 'final', 'date' => '2026-11-07'])->assertUnprocessable();
    $this->getJson("/api/v1/construction-trades/bids/{$bid}/billings")->assertJsonCount(3, 'data');
});

test('site costs are controlled against the budget and the measured progress of each position', function (): void {
    $ids = ($this->acceptedBid)();
    $bid = $ids['bid'];

    $this->postJson("/api/v1/construction-trades/bids/{$bid}/site-costs", ['bid_position_id' => $ids['walls'], 'cost_type' => 'labour', 'cost_date' => '2026-11-07', 'description' => 'Ore', 'quantity' => '10', 'unit_cost' => '50'])
        ->assertUnprocessable()->assertJsonValidationErrors('worker_user_id');
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/site-costs", ['bid_position_id' => $ids['walls'], 'cost_type' => 'labour', 'worker_user_id' => $this->user->id, 'cost_date' => '2026-11-07', 'description' => 'Ore', 'quantity' => '10', 'unit_cost' => '50'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/site-costs", ['bid_position_id' => $ids['walls'], 'cost_type' => 'material', 'cost_date' => '2026-11-07', 'description' => 'Pittura', 'quantity' => '4', 'unit_cost' => '30'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/site-costs", ['cost_type' => 'machine', 'cost_date' => '2026-11-07', 'description' => 'Ponteggio', 'quantity' => '1', 'unit_cost' => '80'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/measurements", ['bid_position_id' => $ids['walls'], 'measured_on' => '2026-11-07', 'quantity' => '60'])->assertCreated();

    $control = $this->getJson("/api/v1/construction-trades/bids/{$bid}/budget-control")->assertOk();
    $walls = collect($control->json('data.positions'))->firstWhere('bid_position_id', $ids['walls']);

    expect($walls)->toMatchArray([
        'budget_total' => '2350.00', 'actual_labour_hours' => '10.00', 'actual_total' => '620.00', 'progress_percentage' => '60.00', 'earned_budget' => '1410.00', 'variance' => '790.00',
    ])->and($control->json('data.unassigned_costs'))->toBe('80.00');
});

test('a CSV price list is imported row by row and the tender specification prints', function (): void {
    $listId = ($this->priceList)();
    $csv = "position_code;unit;unit_price;labour_minutes;description_it;description_de\n100.1;m2;12,50;15;Rasatura;Spachteln\n100.2;;5;;Senza unità;\n100.1;m2;13;15;Rasatura fine;Feinspachteln\n";

    $this->post("/api/v1/construction-trades/price-lists/{$listId}/import", ['file' => UploadedFile::fake()->createWithContent('npk.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1)->assertJsonPath('data.errors.0.line', 3);
    $this->getJson("/api/v1/construction-trades/price-lists/{$listId}")->assertJsonPath('data.items.0.unit_price', '13.0000')->assertJsonPath('data.items.0.descriptions.de', 'Feinspachteln');

    $this->post("/api/v1/construction-trades/price-lists/{$listId}/import", ['file' => UploadedFile::fake()->createWithContent('bad.csv', "code;unit\n1;m2\n")], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->postJson('/api/v1/construction-trades/price-lists', ['name' => 'NPK', 'source' => 'npk', 'currency' => 'CHF', 'languages' => ['de']])->assertUnprocessable()->assertJsonValidationErrors('license_reference');

    $ids = ($this->acceptedBid)();
    $pdf = $this->get("/api/v1/construction-trades/bids/{$ids['bid']}/tender-specification?with_prices=0")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

test('generic price lists are shared read-only and other companies stay apart', function (): void {
    $genericId = (string) Str::uuid7();
    DB::table('ct_price_lists')->insert(['id' => $genericId, 'tenant_id' => null, 'name' => 'Generico', 'source' => 'own', 'currency' => 'CHF', 'languages' => '["it"]', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson("/api/v1/construction-trades/price-lists/{$genericId}")->assertOk()->assertJsonPath('data.is_generic', true);
    $this->putJson("/api/v1/construction-trades/price-lists/{$genericId}", ['name' => 'Mio'])->assertForbidden();
    $this->postJson("/api/v1/construction-trades/price-lists/{$genericId}/items", ['position_code' => '1', 'unit' => 'm2', 'unit_price' => '1', 'descriptions' => ['it' => 'x']])->assertForbidden();

    $ids = ($this->acceptedBid)();
    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/construction-trades/bids/{$ids['bid']}")->assertNotFound();
    $this->getJson("/api/v1/construction-trades/price-lists/{$genericId}")->assertOk();

    $foreignContact = verticalContact($other);
    verticalApi(verticalMember($other, 'admin'), $other);
    $bid = $this->postJson('/api/v1/construction-trades/bids', ['contact_id' => $foreignContact->id, 'title' => 'B', 'language' => 'it', 'currency' => 'CHF', 'tax_rate' => '8.1'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/construction-trades/bids/{$bid}/positions", ['price_item_id' => $ids['item'], 'quantity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('price_item_id');
});

test('members stay out, technicians record site work but do not bill', function (): void {
    verticalLedger($this->tenant);
    verticalApi($this->user, $this->tenant);
    $ids = ($this->acceptedBid)();

    verticalApi(verticalMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/construction-trades/bids')->assertForbidden();

    $technician = verticalMember($this->tenant, 'technician');
    verticalApi($technician, $this->tenant);
    $this->postJson("/api/v1/construction-trades/bids/{$ids['bid']}/measurements", ['bid_position_id' => $ids['walls'], 'measured_on' => '2026-11-07', 'quantity' => '10'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$ids['bid']}/site-costs", ['cost_type' => 'labour', 'worker_user_id' => $technician->id, 'cost_date' => '2026-11-07', 'description' => 'Ore', 'quantity' => '8', 'unit_cost' => '45'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/bids/{$ids['bid']}/billings", ['kind' => 'partial', 'basis' => 'measured', 'date' => '2026-11-07'])->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'accountant'), $this->tenant);
    $this->postJson("/api/v1/construction-trades/bids/{$ids['bid']}/billings", ['kind' => 'partial', 'basis' => 'measured', 'date' => '2026-11-07'])->assertCreated();
});
