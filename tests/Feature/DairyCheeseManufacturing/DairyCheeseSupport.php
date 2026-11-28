<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const DC_API = '/api/v1/dairy-cheese';

/**
 * A Swiss cheese dairy with an owner signed in, and a second company. The clock stands on Monday 15 June 2026, so May 2026 is a closed
 * month for the declarations and the settlements.
 */
function dcSetUp(object $test): void
{
    $test->travelTo('2026-06-15 09:00:00');
    $test->tenant = Tenant::create(['name' => 'Caseificio Alpe SA', 'legal_name' => 'Caseificio Alpe SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Latteria', 'legal_name' => 'Altra Latteria Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->owner = treasuryActor($test, 'owner');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dcBreeder(object $test, string $code = 'CH-001', array $overrides = []): string
{
    return (string) $test->postJson(DC_API.'/breeders', array_merge([
        'code' => $code, 'name' => 'Azienda '.$code, 'jurisdiction' => 'CH', 'bdm_number' => 'BDM-'.$code, 'tvd_number' => 'TVD-'.$code,
    ], $overrides))->assertCreated()->json('data.id');
}

function dcTank(object $test, string $code = 'T1', int $capacity = 20000): string
{
    return (string) $test->postJson(DC_API.'/tanks', ['code' => $code, 'name' => 'Silo '.$code, 'capacity_liters' => $capacity])->assertCreated()->json('data.id');
}

/**
 * @return array<string, mixed>
 */
function dcDelivery(object $test, string $breederId, string $tankId, string $date = '2026-05-10', string $session = 'morning', float $liters = 1000.0, float $density = 1.03): array
{
    return $test->postJson(DC_API.'/deliveries', [
        'breeder_id' => $breederId, 'tank_id' => $tankId, 'collection_date' => $date, 'session' => $session, 'liters' => $liters, 'density' => $density,
    ])->assertCreated()->json('data');
}

function dcRack(object $test, int $capacity = 10, string $shelf = 'A1'): string
{
    return (string) $test->postJson(DC_API.'/racks', ['room' => 'Cella 1', 'rack' => 'S1', 'shelf' => $shelf, 'level' => 1, 'capacity_wheels' => $capacity])->assertCreated()->json('data.id');
}

function dcCurve(object $test): void
{
    $test->postJson(DC_API.'/weight-loss-curves', [
        'cheese_type' => 'gruyere_aop', 'name' => 'Gruyère test', 'points' => [['age_months' => 1, 'loss_pct' => 2], ['age_months' => 12, 'loss_pct' => 12]],
    ])->assertCreated();
}

/**
 * Two breeders deliver 1000 l each on 10 May into one tank; a vat of 1500 l is drawn on 20 May (FIFO: breeder A 1000 l, breeder B 500 l)
 * and completed into 3 wheels of 50 kg on a shelf.
 *
 * @return array{a: string, b: string, tank: string, delivery_a: string, delivery_b: string, batch: string, rack: string, wheels: list<string>}
 */
function dcCompletedBatch(object $test): array
{
    dcCurve($test);
    $a = dcBreeder($test, 'CH-001');
    $b = dcBreeder($test, 'CH-002');
    $tank = dcTank($test);
    $deliveryA = (string) dcDelivery($test, $a, $tank, '2026-05-10', 'morning')['id'];
    $deliveryB = (string) dcDelivery($test, $b, $tank, '2026-05-10', 'evening')['id'];
    $rack = dcRack($test);

    $batch = (string) $test->postJson(DC_API.'/vat-batches', [
        'cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-20', 'vat_number' => 'V1', 'tank_usages' => [['tank_id' => $tank, 'liters' => 1500]],
    ])->assertCreated()->json('data.id');

    $test->postJson(DC_API."/vat-batches/{$batch}/complete", [
        'fresh_yield_24h_kg' => 154.5, 'plaque_prefix' => 'GR26-', 'plaque_from' => 1, 'plaque_to' => 3, 'initial_weight_kg' => 50, 'rack_id' => $rack,
    ])->assertOk();

    $wheels = array_column($test->getJson(DC_API.'/wheels?vat_batch_id='.$batch)->assertOk()->json('data'), 'id');

    return ['a' => $a, 'b' => $b, 'tank' => $tank, 'delivery_a' => $deliveryA, 'delivery_b' => $deliveryB, 'batch' => $batch, 'rack' => $rack, 'wheels' => $wheels];
}

function dcContact(object $test, string $name = 'Alpe Rossi'): Contact
{
    return treasuryInTenant($test->tenant, fn (): Contact => Contact::create(['tenant_id' => $test->tenant->id, 'company_name' => $name, 'email' => 'alpe@example.com', 'type' => 'supplier', 'country' => 'CH']));
}
