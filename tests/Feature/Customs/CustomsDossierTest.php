<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsConsolidation;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsDossierCharge;
use Modules\Customs\Services\DossierService;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    Storage::fake('private');
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
    $this->customer = customsContact($this->tenant);
    customsSettings($this->tenant);
    customsApi($this->user, $this->tenant);

    $this->dossier = fn (array $overrides = []): array => $this->postJson('/api/v1/customs/dossiers', [
        'customer_contact_id' => $this->customer->id,
        'service_type' => 'road',
        'direction' => 'import',
        'currency' => 'CHF',
        'destination_country' => 'CH',
        'gross_weight_kg' => 120,
        'volume_m3' => 2,
        'packages' => 4,
        ...$overrides,
    ])->assertCreated()->json('data');
});

afterEach(fn () => app(TenantContext::class)->clear());

function customsGrid(string $currency = 'CHF'): string
{
    $grid = test()->postJson('/api/v1/customs/tariff-grids', ['name' => 'Strada', 'service_type' => 'road', 'currency' => $currency])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/customs/tariff-grids/{$grid}/rates", ['basis' => 'per_kg', 'rate' => '1.5000', 'min_weight_kg' => 0, 'max_weight_kg' => 100, 'minimum_charge' => 50])->assertCreated();
    test()->postJson("/api/v1/customs/tariff-grids/{$grid}/rates", ['basis' => 'per_kg', 'rate' => '1.2000', 'min_weight_kg' => 100, 'max_weight_kg' => 1000])->assertCreated();
    test()->postJson("/api/v1/customs/tariff-grids/{$grid}/rates", ['basis' => 'per_kg', 'rate' => '1.1000', 'min_weight_kg' => 100, 'max_weight_kg' => 1000, 'destination_country' => 'CH'])->assertCreated();

    return $grid;
}

test('a dossier is numbered and opened', function (): void {
    $dossier = ($this->dossier)();

    expect($dossier['dossier_number'])->not->toBeEmpty()->and($dossier['status'])->toBe('open');
});

test('a quote prices weight and destination from the grid with the discount of the customer', function (): void {
    customsGrid();
    $this->putJson('/api/v1/customs/service-discounts', ['contact_id' => $this->customer->id, 'service_type' => 'road', 'discount_percent' => 10])->assertOk();
    $dossier = ($this->dossier)();

    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/quotes/from-grid")->assertCreated()
        ->assertJsonPath('data.total_amount', '118.80')
        ->assertJsonPath('data.discount_percent', '10.00')
        ->assertJsonPath('data.is_spot', false);
});

test('the minimum charge applies and a weight outside the grid is refused', function (): void {
    customsGrid();
    $light = ($this->dossier)(['gross_weight_kg' => 10]);
    $heavy = ($this->dossier)(['gross_weight_kg' => 5000]);

    $this->postJson("/api/v1/customs/dossiers/{$light['id']}/quotes/from-grid")->assertCreated()->assertJsonPath('data.total_amount', '50.00');
    $this->postJson("/api/v1/customs/dossiers/{$heavy['id']}/quotes/from-grid")->assertUnprocessable();
});

test('a spot quote is the sum of its lines, and a sent quote moves the dossier', function (): void {
    $dossier = ($this->dossier)();

    $quote = $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/quotes/spot", ['lines' => [
        ['description' => 'Trasporto', 'quantity' => 3, 'unit_price' => '33.3333'],
        ['description' => 'Pratica doganale', 'quantity' => 1, 'unit_price' => '0.10'],
    ]])->assertCreated()->assertJsonPath('data.total_amount', '100.10')->assertJsonPath('data.is_spot', true)->json('data.id');

    $this->postJson("/api/v1/customs/quotes/{$quote}/status", ['status' => 'sent'])->assertOk();
    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}")->assertJsonPath('data.status', 'quoted');
    $this->postJson("/api/v1/customs/quotes/{$quote}/status", ['status' => 'accepted'])->assertOk();
    $this->postJson("/api/v1/customs/quotes/{$quote}/status", ['status' => 'rejected'])->assertUnprocessable();
    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/quotes")->assertJsonCount(1, 'data');
});

test('profitability separates direct and indirect costs, with exact decimals', function (): void {
    $dossier = ($this->dossier)();
    $charge = fn (array $data) => $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", $data)->assertCreated();

    $charge(['kind' => 'revenue', 'category' => 'freight', 'description' => 'Trasporto', 'amount' => '1000.10']);
    $charge(['kind' => 'cost', 'category' => 'freight', 'description' => 'Vettore', 'amount' => '700.05']);
    $charge(['kind' => 'cost', 'category' => 'other', 'is_direct' => false, 'description' => 'Ufficio', 'amount' => '100.02']);

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/profitability")->assertOk()
        ->assertJsonPath('data.revenue', '1000.10')
        ->assertJsonPath('data.direct_costs', '700.05')
        ->assertJsonPath('data.indirect_costs', '100.02')
        ->assertJsonPath('data.contribution_margin', '300.05')
        ->assertJsonPath('data.result', '200.03')
        ->assertJsonPath('data.margin_percent', '20.00');
});

test('costs and margin are reserved to finance and customs roles', function (string $role, bool $sees): void {
    $dossier = ($this->dossier)();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'revenue', 'category' => 'freight', 'description' => 'Trasporto', 'amount' => '500.00'])->assertCreated();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'cost', 'category' => 'freight', 'description' => 'Vettore', 'amount' => '300.00'])->assertCreated();

    customsApi(customsMember($this->tenant, $role), $this->tenant);
    $profitability = $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/profitability");
    $summary = $this->getJson("/api/v1/customs/dossiers/monthly-summary?contact_id={$this->customer->id}&year=2026&month=11")->assertOk();

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}")->assertOk();
    expect($profitability->status())->toBe($sees ? 200 : 403)
        ->and($summary->json('data.dossiers.0.revenue'))->toBe('500.00')
        ->and(array_key_exists('direct_costs', $summary->json('data.dossiers.0')))->toBe($sees)
        ->and(array_key_exists('result', $summary->json('data.dossiers.0')))->toBe($sees);
})->with([
    'owner' => ['owner', true],
    'accountant' => ['accountant', true],
    'customs' => ['customs', true],
    'sales' => ['sales', false],
    'warehouse' => ['warehouse', false],
]);

test('the customs.profitability permission opens the figures to a single warehouse user', function (): void {
    $dossier = ($this->dossier)();

    customsApi(customsMember($this->tenant, 'warehouse', ['customs.profitability']), $this->tenant);

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/profitability")->assertOk();
});

test('duty and VAT come from the decisions, once, and a correction updates them', function (): void {
    $dossier = ($this->dossier)();
    $declaration = customsDeclaration($this->tenant, $this->user);
    $declaration->update(['dossier_id' => $dossier['id'], 'mrn' => '26CHEI000000000001']);

    customsArchive($this->tenant, customsDutyDecisionXml());
    customsArchive($this->tenant, customsVatDecisionXml());
    customsApi($this->user, $this->tenant);

    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/import-assessments")->assertOk()->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/import-assessments")->assertOk()->assertJsonCount(2, 'data');

    customsArchive($this->tenant, customsDutyDecisionXml(version: 2, duty: '260.00'));
    customsApi($this->user, $this->tenant);
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/import-assessments")->assertOk()->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/profitability")->assertJsonPath('data.direct_costs', '277.30');
});

test('the billed duty and VAT are verified against the assessment', function (): void {
    $dossier = ($this->dossier)();
    customsDeclaration($this->tenant, $this->user)->update(['dossier_id' => $dossier['id'], 'mrn' => '26CHEI000000000001']);
    customsArchive($this->tenant, customsDutyDecisionXml());
    customsArchive($this->tenant, customsVatDecisionXml());
    customsApi($this->user, $this->tenant);

    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'revenue', 'category' => 'duty', 'description' => 'Dazio', 'amount' => '250.00'])->assertCreated();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'revenue', 'category' => 'import_vat', 'description' => 'IVA', 'amount' => '17.00'])->assertCreated();

    $check = $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}/assessment-check")->assertOk()->json('data');

    expect($check['matches'])->toBeFalse()
        ->and($check['lines'][0])->toMatchArray(['category' => 'duty', 'matches' => true])
        ->and($check['lines'][1])->toMatchArray(['category' => 'import_vat', 'assessed' => '17.30', 'billed' => '17.00', 'difference' => '-0.30', 'matches' => false]);
});

test('a dossier invoices its revenue once through the invoicing flow', function (): void {
    app(OpenFiscalYearAction::class)->execute($this->tenant->id, 2026);
    customsLedgerAccount($this->tenant, '3000', 'revenue');
    $dossier = ($this->dossier)();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'revenue', 'category' => 'freight', 'description' => 'Trasporto', 'amount' => '100.00'])->assertCreated();

    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/invoice", ['date' => '2026-11-07'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'tax rate'));

    CustomsDossierCharge::query()->update(['tax_rate' => '8.10']);

    $response = $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/invoice", ['date' => '2026-11-07']);

    expect($response->status())->toBe(201, (string) $response->getContent());

    $invoice = Invoice::query()->findOrFail($response->json('data.invoice_id'));

    expect((string) $invoice->contact_id)->toBe((string) $this->customer->id)
        ->and((float) $invoice->total_net)->toBe(100.0)
        ->and($invoice->items()->count())->toBe(1);

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}")->assertJsonPath('data.status', 'invoiced');
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/invoice", ['date' => '2026-11-07'])->assertUnprocessable();
});

test('a groupage shares the freight by weight, to the cent, once', function (): void {
    $first = ($this->dossier)(['gross_weight_kg' => 100]);
    $second = ($this->dossier)(['gross_weight_kg' => 100]);
    $third = ($this->dossier)(['gross_weight_kg' => 100]);
    $id = $this->postJson('/api/v1/customs/consolidations', ['reference' => 'GRP-1', 'freight_cost' => '100.00', 'currency' => 'CHF'])->assertCreated()->json('data.id');

    foreach ([$first, $second, $third] as $dossier) {
        $this->postJson("/api/v1/customs/consolidations/{$id}/dossiers/{$dossier['id']}")->assertOk();
    }

    $shares = $this->postJson("/api/v1/customs/consolidations/{$id}/allocate")->assertOk()->json('data');

    expect(array_column($shares, 'share'))->toBe(['33.33', '33.33', '33.34'])
        ->and(number_format((float) CustomsDossierCharge::query()->sum('amount'), 2, '.', ''))->toBe('100.00');

    $this->postJson("/api/v1/customs/consolidations/{$id}/allocate")->assertUnprocessable();
    $late = ($this->dossier)();
    $this->postJson("/api/v1/customs/consolidations/{$id}/dossiers/{$late['id']}")->assertUnprocessable();
});

test('a dossier of a shared-out groupage cannot move to another one, and a stale copy cannot share the freight twice', function (): void {
    $dossier = ($this->dossier)(['gross_weight_kg' => 100]);
    $first = $this->postJson('/api/v1/customs/consolidations', ['reference' => 'GRP-1', 'freight_cost' => '100.00', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $second = $this->postJson('/api/v1/customs/consolidations', ['reference' => 'GRP-2', 'freight_cost' => '80.00', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/customs/consolidations/{$first}/dossiers/{$dossier['id']}")->assertOk();
    app(TenantContext::class)->setTenant($this->tenant);
    $stale = CustomsConsolidation::query()->findOrFail($first);

    $this->postJson("/api/v1/customs/consolidations/{$first}/allocate")->assertOk();

    $this->postJson("/api/v1/customs/consolidations/{$second}/dossiers/{$dossier['id']}")->assertUnprocessable();
    expect(CustomsDossier::query()->findOrFail($dossier['id'])->consolidation_id)->toBe($first)
        ->and(fn () => app(DossierService::class)->allocateConsolidation($stale))->toThrow(CustomsException::class)
        ->and(CustomsDossierCharge::query()->count())->toBe(1);
});

test('a split delivery closes the dossier on its last part and cannot exceed it', function (): void {
    $dossier = ($this->dossier)();

    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/deliveries", ['packages' => 1, 'recipient' => 'Filiale A'])->assertCreated();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/deliveries", ['packages' => 9])->assertUnprocessable();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/deliveries", ['packages' => 3, 'recipient' => 'Filiale B'])->assertCreated();

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}")->assertJsonPath('data.status', 'delivered')->assertJsonCount(2, 'data.deliveries');
});

test('a document stays on the private disk and its path is not shown', function (): void {
    $dossier = ($this->dossier)();

    $response = $this->post("/api/v1/customs/dossiers/{$dossier['id']}/documents", [
        'file' => UploadedFile::fake()->create('fattura.pdf', 10, 'application/pdf'),
        'document_kind' => 'invoice',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.storage_path');

    expect($response->json('data.file_name'))->toBe('fattura.pdf');
    $this->post("/api/v1/customs/dossiers/{$dossier['id']}/documents", ['file' => UploadedFile::fake()->create('x.exe', 1), 'document_kind' => 'invoice'], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('the monthly summary lists the dossiers of the customer', function (): void {
    $dossier = ($this->dossier)();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'revenue', 'category' => 'freight', 'description' => 'Trasporto', 'amount' => '100.00']);
    $this->travelTo('2026-12-02 10:00:00');
    ($this->dossier)();

    $this->getJson("/api/v1/customs/dossiers/monthly-summary?contact_id={$this->customer->id}&year=2026&month=11")->assertOk()
        ->assertJsonPath('data.revenue_total', '100.00')
        ->assertJsonCount(1, 'data.dossiers');
});

test('dossiers, grids and consolidations of another company are out of reach', function (): void {
    $dossier = ($this->dossier)();
    $grid = customsGrid();
    $consolidation = $this->postJson('/api/v1/customs/consolidations', ['reference' => 'GRP-1', 'freight_cost' => '1', 'currency' => 'CHF'])->json('data.id');

    $other = customsTenant();
    $foreign = customsContact($other);
    customsApi(customsMember($other), $other);
    $own = ($this->dossier)(['customer_contact_id' => $foreign->id]);

    $this->getJson("/api/v1/customs/dossiers/{$dossier['id']}")->assertNotFound();
    $this->postJson("/api/v1/customs/dossiers/{$dossier['id']}/charges", ['kind' => 'cost', 'category' => 'other', 'description' => 'x', 'amount' => 1])->assertNotFound();
    $this->getJson("/api/v1/customs/tariff-grids/{$grid}")->assertNotFound();
    $this->postJson("/api/v1/customs/consolidations/{$consolidation}/dossiers/{$own['id']}")->assertNotFound();
    $this->postJson("/api/v1/customs/dossiers/{$own['id']}/quotes/from-grid", ['tariff_grid_id' => $grid])->assertUnprocessable();
    $this->getJson('/api/v1/customs/dossiers')->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/customs/dossiers', ['customer_contact_id' => $this->customer->id, 'service_type' => 'road', 'direction' => 'import', 'currency' => 'CHF'])->assertUnprocessable();
});

test('a manual status change follows the dossier flow and rejects skipped or final transitions', function (): void {
    $dossier = ($this->dossier)();
    $url = "/api/v1/customs/dossiers/{$dossier['id']}";

    $this->putJson($url, ['status' => 'delivered'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson($url, ['status' => 'invoiced'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson($url, ['status' => 'open', 'notes' => 'same status is fine'])->assertOk();
    $this->putJson($url, ['status' => 'in_transit'])->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->putJson($url, ['status' => 'open'])->assertUnprocessable();
    $this->putJson($url, ['status' => 'delivered'])->assertOk();
    $this->putJson($url, ['status' => 'closed'])->assertOk();
    $this->putJson($url, ['status' => 'open'])->assertUnprocessable();
    $this->putJson($url, ['status' => 'cancelled'])->assertUnprocessable();

    expect(CustomsDossier::query()->findOrFail($dossier['id'])->status->value)->toBe('closed');
});
