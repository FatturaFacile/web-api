<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
    ivActAs($this->tenant);
});

/**
 * @return array<string, mixed>
 */
function ventilationPayload(string $date = '2025-03-01'): array
{
    return [
        'entry_date' => $date,
        'gross_takings' => 1000,
        'purchase_basis' => [['rate' => 22, 'amount' => 600], ['rate' => 10, 'amount' => 300], ['rate' => 4, 'amount' => 100]],
    ];
}

it('previews the ventilation without saving anything', function (): void {
    $response = $this->postJson('/api/v1/italian-vat/receipts/ventilation/preview', ventilationPayload())->assertOk();

    expect(array_column($response->json('data.lines'), 'taxable'))->toBe(['491.80', '272.73', '96.15'])
        ->and($response->json('data.total_vat'))->toBe('139.32');

    $this->getJson('/api/v1/italian-vat/registers/'.ivSectionId('COR').'?from=2025-01-01&to=2025-12-31')->assertJsonPath('data.totals.taxable', '0.00');
});

it('registers the ventilated takings with one numbered line per rate', function (): void {
    $response = $this->postJson('/api/v1/italian-vat/receipts/ventilation', ventilationPayload())->assertCreated();

    expect(array_column($response->json('data.entries'), 'protocol_number'))->toBe([1, 2, 3])
        ->and(array_column($response->json('data.entries'), 'vat_amount'))->toBe(['108.20', '27.27', '3.85'])
        ->and($response->json('data.ventilation.total_taxable'))->toBe('860.68');

    $this->getJson('/api/v1/italian-vat/registers/'.ivSectionId('COR').'?from=2025-03-01&to=2025-03-31')
        ->assertJsonPath('data.totals.taxable', '860.68')
        ->assertJsonPath('data.totals.vat', '139.32')
        ->assertJsonPath('data.totals.gross', '1000.00');
});

it('registers takings already split by rate and keeps exempt takings under their nature', function (): void {
    $response = $this->postJson('/api/v1/italian-vat/receipts/by-rate', [
        'entry_date' => '2025-03-02',
        'lines' => [['rate' => 22, 'gross' => 122], ['rate' => 0, 'gross' => 50, 'nature' => 'N4']],
    ])->assertCreated();

    expect($response->json('data.0.taxable_amount'))->toBe('100.00')
        ->and($response->json('data.0.vat_amount'))->toBe('22.00')
        ->and($response->json('data.1.vat_nature'))->toBe('N4');

    $this->postJson('/api/v1/italian-vat/receipts/by-rate', ['entry_date' => '2025-03-02', 'lines' => [['rate' => 0, 'gross' => 50]]])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'vat_nature_required');
});

it('refuses a ventilation without purchases to base it on', function (): void {
    $this->postJson('/api/v1/italian-vat/receipts/ventilation', ['entry_date' => '2025-03-01', 'gross_takings' => 100, 'purchase_basis' => [['rate' => 22, 'amount' => 0]]])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'purchase_basis_empty');
});

it('refuses takings in a period closed by a definitive print', function (): void {
    $this->postJson('/api/v1/italian-vat/receipts/ventilation', ventilationPayload('2025-03-01'))->assertCreated();
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('COR').'/prints', ['year' => 2025, 'period_to' => '2025-03-31'])->assertCreated();

    $this->postJson('/api/v1/italian-vat/receipts/ventilation', ventilationPayload('2025-03-15'))
        ->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'period_locked');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function goodsMovement(string $contactId, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/italian-vat/goods-movements', $overrides + [
        'movement_date' => '2025-03-03',
        'movement_type' => 'received',
        'contact_id' => $contactId,
        'description' => 'Steel bars',
        'unit' => 'kg',
        'quantity' => 100,
        'value' => 5000,
    ]);
}

it('records the goods of a third party with progressive protocols and tracks the balance', function (): void {
    $owner = ivContact($this->tenant, ['company_name' => 'Cliente Lavorazione']);

    goodsMovement((string) $owner->id)->assertCreated()->assertJsonPath('data.protocol_number', 1);
    goodsMovement((string) $owner->id, ['movement_type' => 'processed', 'quantity' => 40, 'value' => 2000, 'movement_date' => '2025-03-04'])
        ->assertCreated()->assertJsonPath('data.protocol_number', 2);
    goodsMovement((string) $owner->id, ['movement_type' => 'received', 'description' => 'Copper', 'quantity' => 10, 'value' => 900])->assertCreated();

    $balances = collect($this->getJson('/api/v1/italian-vat/goods-balances')->assertOk()->json('data'))->keyBy('description');

    expect($balances['Steel bars']['held_quantity'])->toBe('60.00')
        ->and($balances['Steel bars']['held_value'])->toBe('3000.00')
        ->and($balances['Copper']['held_quantity'])->toBe('10.00');

    $asOf = $this->getJson('/api/v1/italian-vat/goods-balances?as_of=2025-03-03')->assertOk()->json('data');

    expect(collect($asOf)->firstWhere('description', 'Steel bars')['held_quantity'])->toBe('100.00');
});

it('refuses to release goods beyond what the company holds for that third party', function (): void {
    $owner = ivContact($this->tenant, ['company_name' => 'Cliente A']);
    $other = ivContact($this->tenant, ['company_name' => 'Cliente B', 'fiscal_code' => 'BNCNNA85B41F205X']);

    goodsMovement((string) $owner->id)->assertCreated();

    goodsMovement((string) $owner->id, ['movement_type' => 'returned', 'quantity' => 150])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'goods_insufficient_balance')
        ->assertJsonPath('invalid_params.0.params.held', '100.00');

    goodsMovement((string) $other->id, ['movement_type' => 'sold', 'quantity' => 1])->assertUnprocessable();
    goodsMovement((string) $owner->id, ['movement_type' => 'returned', 'quantity' => 100])->assertCreated();
    goodsMovement((string) $owner->id, ['movement_type' => 'lost'])->assertUnprocessable();
});

it('prints the goods register definitively and locks its movements', function (): void {
    $owner = ivContact($this->tenant);
    goodsMovement((string) $owner->id)->assertCreated();
    goodsMovement((string) $owner->id, ['movement_type' => 'returned', 'quantity' => 10, 'movement_date' => '2025-03-10'])->assertCreated();

    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('MCT').'/prints', ['year' => 2025, 'period_to' => '2025-03-31'])
        ->assertCreated()
        ->assertJsonPath('data.entries_count', 2)
        ->assertJsonPath('data.total_taxable', '10000.00');

    goodsMovement((string) $owner->id, ['movement_date' => '2025-03-20'])->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'period_locked');
    goodsMovement((string) $owner->id, ['movement_date' => '2025-04-02'])->assertCreated()->assertJsonPath('data.protocol_number', 3);

    $movements = $this->getJson('/api/v1/italian-vat/goods-movements?contact_id='.$owner->id)->assertOk()->json('data');

    expect($movements)->toHaveCount(3)
        ->and($movements[0]['print_id'])->not->toBeNull()
        ->and($movements[2]['print_id'])->toBeNull();
});

it('keeps the goods registers of two tenants apart', function (): void {
    $owner = ivContact($this->tenant);
    goodsMovement((string) $owner->id)->assertCreated();

    $other = ivTenant('Other');
    ivActAs($other);

    expect($this->getJson('/api/v1/italian-vat/goods-movements')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/italian-vat/goods-balances')->assertOk()->json('data'))->toBe([]);
});
