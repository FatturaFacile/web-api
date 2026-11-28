<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianReceipt;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

/**
 * Two register operations of 10 November 2027: a C02 load of 100 kg into a tank and the S02 return of 400 kg to the farmer (deadline 16 November).
 */
function omRegisterRows(object $test): void
{
    $tank = omTank($test, ['code' => 'S1']);
    $cycle = omStartCycle($test, omDelivery($test));
    omComplete($test, $cycle, ['pickup_kg' => 400, 'molenda_kg' => 100, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])->assertOk();
}

test('the register lists the operations with the days left to the SIAN deadline', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    $this->getJson(OM_API.'/sian-movements')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.progressive', 2)
        ->assertJsonPath('data.0.causal', 'S02')
        ->assertJsonPath('data.0.transmission_deadline', '2027-11-16')
        ->assertJsonPath('data.0.days_left', 6)
        ->assertJsonPath('data.0.alert_level', 'ok')
        ->assertJsonPath('data.1.causal', 'C02')
        ->assertJsonPath('data.1.to_tank_code', 'S1');

    $this->getJson(OM_API.'/sian-movements?causal=C02')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(OM_API.'/sian-movements?status=ACK_OK')->assertOk()->assertJsonCount(0, 'data');
});

test('the alerts flag the operations that are due soon and the overdue ones', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    $this->getJson(OM_API.'/sian-movements/compliance-alerts')->assertOk()->assertJsonPath('data.overdue', 0)->assertJsonPath('data.due_soon', 0)->assertJsonCount(0, 'data.items');

    $this->travelTo('2027-11-15 09:00:00');

    $this->getJson(OM_API.'/sian-movements/compliance-alerts')
        ->assertOk()
        ->assertJsonPath('data.due_soon', 2)
        ->assertJsonPath('data.overdue', 0)
        ->assertJsonPath('data.items.0.days_left', 1)
        ->assertJsonPath('data.items.0.alert_level', 'due_soon');

    $this->travelTo('2027-11-18 09:00:00');

    $this->getJson(OM_API.'/sian-movements/compliance-alerts')->assertOk()->assertJsonPath('data.overdue', 2)->assertJsonPath('data.items.0.days_left', -2)->assertJsonPath('data.items.0.alert_level', 'overdue');
    $this->getJson(OM_API.'/sian-movements?overdue=1')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.alert_level', 'overdue');
});

test('the transmission validates the operations and keeps the protocol of the receipt', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    $response = $this->postJson(OM_API.'/sian-movements/transmit', [])
        ->assertOk()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.transmitted', 2)
        ->assertJsonPath('data.skipped', []);

    $protocol = (string) $response->json('data.protocol');

    expect($protocol)->toStartWith('SBX-');

    $movements = SianMovement::query()->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->every(fn (SianMovement $m): bool => $m->status->value === 'ACK_OK' && $m->sian_protocol === $protocol && $m->transmitted_at !== null))->toBeTrue()
        ->and($movements->first()->payload_snapshot)->toContain('<Operazione');

    $this->getJson(OM_API.'/sian-movements/compliance-alerts')->assertOk()->assertJsonCount(0, 'data.items');
    $this->postJson(OM_API.'/sian-movements/transmit', [])->assertUnprocessable()->assertJsonPath('code', 'nothing_to_transmit');
});

test('the transmission can be limited to the named operations', function (): void {
    omSetUp($this);
    omRegisterRows($this);
    $first = SianMovement::query()->where('causal', 'C02')->firstOrFail();

    $this->postJson(OM_API.'/sian-movements/transmit', ['movement_ids' => [$first->id]])->assertOk()->assertJsonPath('data.transmitted', 1);

    expect(SianMovement::query()->where('status', 'ACK_OK')->count())->toBe(1)->and(SianMovement::query()->where('status', 'DRAFT')->count())->toBe(1);
});

test('an operation without the counterparty CUAA is skipped and reported', function (): void {
    omSetUp($this);
    omRegisterRows($this);
    SianMovement::query()->create([
        'progressive' => 99, 'operation_date' => '2027-11-10', 'transmission_deadline' => '2027-11-16', 'causal' => 'S01', 'quantity_kg' => 10, 'quantity_l' => 10.917,
        'status' => 'DRAFT',
    ]);

    $this->postJson(OM_API.'/sian-movements/transmit', [])
        ->assertOk()
        ->assertJsonPath('data.transmitted', 2)
        ->assertJsonPath('data.skipped.0.code', 'counterparty_required')
        ->assertJsonPath('data.skipped.0.progressive', 99);

    $skipped = SianMovement::query()->where('progressive', 99)->firstOrFail();

    expect($skipped->status->value)->toBe('DRAFT')->and($skipped->error_message)->toBe('counterparty_required');
});

test('a receipt refused by the SIAN marks the operations as errors that can be sent again', function (): void {
    omSetUp($this);
    omRegisterRows($this);
    $refusing = new OmFakeSianTransport(new SianReceipt(false, null, 'sian_refused_500'));
    app()->instance(SianTransport::class, $refusing);

    $this->postJson(OM_API.'/sian-movements/transmit', [])
        ->assertOk()
        ->assertJsonPath('data.accepted', false)
        ->assertJsonPath('data.protocol', null)
        ->assertJsonPath('data.message', 'sian_refused_500');

    $movement = SianMovement::query()->firstOrFail();

    expect($movement->status->value)->toBe('ACK_ERROR')->and($movement->error_message)->toBe('sian_refused_500');

    $accepting = new OmFakeSianTransport(new SianReceipt(true, 'PROT-2027-1'));
    app()->instance(SianTransport::class, $accepting);

    $this->postJson(OM_API.'/sian-movements/transmit', [])->assertOk()->assertJsonPath('data.accepted', true)->assertJsonPath('data.protocol', 'PROT-2027-1');

    expect(SianMovement::query()->where('status', 'ACK_OK')->count())->toBe(2)->and($accepting->calls)->toBe(1);
});

test('the official file is downloaded as XML or CSV and the operations are marked as exported', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    $xml = $this->post(OM_API.'/sian-movements/export/xml', [], ['Accept' => 'application/xml'])->assertOk();

    expect($xml->headers->get('Content-Type'))->toContain('application/xml')
        ->and($xml->headers->get('Content-Disposition'))->toContain('sian-register-')
        ->and((string) simplexml_load_string($xml->getContent())['cuaa'])->toBe('01234567897')
        ->and((string) simplexml_load_string($xml->getContent())['operazioni'])->toBe('2');

    expect(SianMovement::query()->where('status', 'EXPORTED')->count())->toBe(2);

    $csv = $this->post(OM_API.'/sian-movements/export/csv', [], ['Accept' => 'text/csv'])->assertOk();

    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('progressive;operation_date;causal')
        ->and($csv->getContent())->toContain('RSSMRA85T10A562S');

    // An exported file has not been sent: the operations stay pending.
    $this->postJson(OM_API.'/sian-movements/transmit', [])->assertOk()->assertJsonPath('data.transmitted', 2);
});

test('an unknown export format is not found', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    $this->postJson(OM_API.'/sian-movements/export/pdf', [])->assertNotFound();
});

test('the register operations of another company are not visible nor transmitted', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(OM_API.'/sian-movements')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(OM_API.'/sian-movements/transmit', [])->assertUnprocessable()->assertJsonPath('code', 'nothing_to_transmit');
});

test('a read-only member reads the register but cannot transmit', function (): void {
    omSetUp($this);
    omRegisterRows($this);

    treasuryActor($this, 'accountant_readonly');

    $this->getJson(OM_API.'/sian-movements')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(OM_API.'/sian-movements/compliance-alerts')->assertOk();
    $this->postJson(OM_API.'/sian-movements/transmit', [])->assertForbidden();
});
