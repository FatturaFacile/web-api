<?php

declare(strict_types=1);

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsGatewayMessage;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsTariffNumber;
use Modules\Customs\Services\Edec\EdecSchemaValidator;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('private');
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
    $this->consignee = customsContact($this->tenant);
    customsSettings($this->tenant);
    customsApi($this->user, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a declaration is numbered and totals its lines with exact decimals', function (): void {
    $response = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->assertCreated();

    $response->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total_gross_mass', '125.500')
        ->assertJsonPath('data.total_net_mass', '110.250')
        ->assertJsonPath('data.total_statistical_value', '1300.50')
        ->assertJsonPath('data.total_vat_value', '1420.40')
        ->assertJsonPath('data.total_packages', 13)
        ->assertJsonCount(2, 'data.items');

    expect($response->json('data.trader_declaration_number'))->not->toBeEmpty();
});

test('a complete import passes the checks and its message passes the BAZG schema', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');

    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk()
        ->assertJsonPath('data.errors', [])
        ->assertJsonPath('data.status', 'validated');

    $xml = $this->get("/api/v1/customs/declarations/{$id}/message")->assertOk()->getContent();

    expect(app(EdecSchemaValidator::class)->errors($xml, EdecSchemaValidator::DECLARATION))->toBe([])
        ->and($xml)->toContain('<serviceType>1</serviceType>', '<commodityCode>2204.2100</commodityCode>');
});

test('an export carries its destination and passes the BAZG schema', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee, ['declaration_type' => 'export', 'destination_country' => 'DE', 'dispatch_country' => null]))->json('data.id');

    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk()->assertJsonPath('data.errors', []);

    expect($this->get("/api/v1/customs/declarations/{$id}/message")->getContent())->toContain('<serviceType>2</serviceType>', '<deliveryDestination>DE</deliveryDestination>');
});

test('a transit declaration has its own checks and no e-dec message', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', ['declaration_type' => 'transit', 'items' => [customsItemPayload()]])->json('data.id');

    $fields = collect($this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk()->json('data.errors'))->pluck('field')->all();
    expect($fields)->toContain('customs_office_number', 'dispatch_country', 'destination_country');

    $this->putJson("/api/v1/customs/declarations/{$id}", ['customs_office_number' => 'CH001251', 'dispatch_country' => 'IT', 'destination_country' => 'DE']);
    $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertJsonPath('data.status', 'validated');
    $this->get("/api/v1/customs/declarations/{$id}/message")->assertUnprocessable();
    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'accepted');
});

test('the checks name missing data, schema errors and invalid masses', function (): void {
    $payload = customsImportPayload($this->consignee, ['dispatch_country' => null, 'transport_mode' => null]);
    $payload['items'] = [customsItemPayload(['gross_mass' => '5', 'net_mass' => '9', 'origin_country' => null])];
    $id = $this->postJson('/api/v1/customs/declarations', $payload)->json('data.id');

    $errors = collect($this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertOk()->assertJsonPath('data.status', 'draft')->json('data.errors'));

    expect($errors->pluck('field')->all())->toContain('dispatch_country', 'items.1.net_mass', 'items.1.origin_country', 'schema');
});

test('codes are checked against the tables of the day, and an empty table only warns', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');

    expect($this->postJson("/api/v1/customs/declarations/{$id}/validate")->json('data.warnings'))->not->toBe([]);

    CustomsPackagingType::query()->create(['code' => 'PA', 'name_it' => 'Pallet']);
    CustomsTariffNumber::query()->create(['code' => '2201.1000', 'valid_to' => '2020-12-31']);

    $fields = collect($this->postJson("/api/v1/customs/declarations/{$id}/validate")->json('data.errors'))->pluck('field')->all();

    expect($fields)->toContain('items.1.packaging_type', 'items.1.commodity_code', 'items.2.commodity_code');
});

test('field shapes come from the e-dec schema', function (): void {
    $payload = customsImportPayload($this->consignee, ['trader_declaration_number' => 'A/B', 'dispatch_country' => 'italy']);
    $payload['items'][0]['commodity_code'] = '12345678901';

    $this->postJson('/api/v1/customs/declarations', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['trader_declaration_number', 'dispatch_country', 'items.0.commodity_code']);
});

test('the local channel accepts, gives a reference that is not an MRN and logs the messages', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertUnprocessable();
    $this->postJson("/api/v1/customs/declarations/{$id}/validate");

    $response = $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.gateway', 'local');

    expect($response->json('data.mrn'))->toStartWith('LOCAL')
        ->and(CustomsGatewayMessage::query()->where('declaration_id', $id)->count())->toBe(2);

    $path = CustomsGatewayMessage::query()->where('declaration_id', $id)->where('direction', 'out')->sole()->storage_path;
    Storage::disk('private')->assertExists($path);
});

test('a submitted declaration cannot change, be cancelled here or be deleted', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/validate");
    $this->postJson("/api/v1/customs/declarations/{$id}/submit");

    $this->putJson("/api/v1/customs/declarations/{$id}", ['trader_reference' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/customs/declarations/{$id}/cancel")->assertUnprocessable();
    $this->deleteJson("/api/v1/customs/declarations/{$id}")->assertUnprocessable();
});

test('changing a validated declaration sends it back to draft', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$id}/validate");

    $this->putJson("/api/v1/customs/declarations/{$id}", ['items' => [customsItemPayload()]])->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total_gross_mass', '100.000');
});

test('a draft comes from a delivery note with its consignee, lines and product tariff code', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $product = new Product;
    $product->tenant_id = $this->tenant->id;
    $product->fill(['sku' => 'ACQ-1', 'name' => 'Acqua', 'hs_code' => '2201.1000', 'sale_price' => 1, 'cost_price' => 1]);
    $product->save();

    $note = new DeliveryNote;
    $note->tenant_id = $this->tenant->id;
    $note->fill(['contact_id' => $this->consignee->id, 'ddt_number' => 'DDT-9', 'date' => '2026-11-01']);
    $note->save();

    $item = new DeliveryNoteItem;
    $item->tenant_id = $this->tenant->id;
    $item->fill(['delivery_note_id' => $note->id, 'product_id' => $product->id, 'description' => 'Acqua', 'quantity' => 2, 'unit_price' => 1, 'tax_rate' => 0]);
    $item->save();

    $this->postJson('/api/v1/customs/declarations/from-delivery-note', ['delivery_note_id' => $note->id, 'declaration_type' => 'export'])->assertCreated()
        ->assertJsonPath('data.delivery_note_id', $note->id)
        ->assertJsonPath('data.consignee_contact_id', $this->consignee->id)
        ->assertJsonPath('data.items.0.product_id', $product->id)
        ->assertJsonPath('data.items.0.commodity_code', '2201.1000');
});

test('tariff lines used before can be found and reused without masses or values', function (): void {
    $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee));
    $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee));

    $history = $this->getJson('/api/v1/customs/tariff-history?search=acqua&commodity_code=2201')->assertOk()->json('data');

    expect($history)->toHaveCount(1)->and((int) $history[0]['times_used'])->toBe(2);

    $target = $this->postJson('/api/v1/customs/declarations', ['declaration_type' => 'import'])->json('data.id');
    $response = $this->postJson("/api/v1/customs/declarations/{$target}/items/reuse/{$history[0]['last_item_id']}")->assertOk();

    $response->assertJsonPath('data.items.0.commodity_code', '2201.1000')
        ->assertJsonPath('data.items.0.gross_mass', '0.000')
        ->assertJsonPath('data.items.0.statistical_value', '0.00');
});

test('the internal PDF copy is produced', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');

    expect(customsResponseIsPdf($this->get("/api/v1/customs/declarations/{$id}/pdf")->assertOk()))->toBeTrue();
});

test('a declaration of another company cannot be reached or reference foreign records', function (): void {
    $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee))->json('data.id');
    $itemId = CustomsDeclarationItem::query()->where('declaration_id', $id)->value('id');

    $other = customsTenant();
    $user = customsMember($other);
    $foreignContact = customsContact($other);
    customsApi($user, $other);

    foreach (['get' => "/api/v1/customs/declarations/{$id}", 'post' => "/api/v1/customs/declarations/{$id}/validate"] as $method => $uri) {
        $this->json($method, $uri)->assertNotFound();
    }

    $this->getJson('/api/v1/customs/declarations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/customs/tariff-history')->assertOk()->assertJsonCount(0, 'data');

    $own = $this->postJson('/api/v1/customs/declarations', ['declaration_type' => 'import'])->json('data.id');
    $this->postJson("/api/v1/customs/declarations/{$own}/items/reuse/{$itemId}")->assertNotFound();

    customsApi($this->user, $this->tenant);
    $this->postJson('/api/v1/customs/declarations', customsImportPayload($foreignContact))->assertUnprocessable()->assertJsonValidationErrors(['consignee_contact_id']);
});

test('readers read, and only customs and finance roles write', function (string $role, int $readStatus, bool $canWrite): void {
    $user = customsMember($this->tenant, $role);
    customsApi($user, $this->tenant);

    expect($this->getJson('/api/v1/customs/declarations')->status())->toBe($readStatus)
        ->and($this->postJson('/api/v1/customs/declarations', ['declaration_type' => 'import'])->status() === 403)->toBe(! $canWrite);
})->with([
    ['owner', 200, true],
    ['accountant', 200, true],
    ['customs', 200, true],
    ['warehouse', 200, false],
    ['sales', 200, false],
    ['member', 403, false],
]);

test('only the declaration of the active tenant is scoped by the model', function (): void {
    $this->postJson('/api/v1/customs/declarations', customsImportPayload($this->consignee));
    app(TenantContext::class)->setTenant(customsTenant());

    expect(CustomsDeclaration::query()->count())->toBe(0);
});
