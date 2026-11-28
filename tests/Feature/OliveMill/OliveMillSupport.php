<?php

declare(strict_types=1);

use App\Contracts\Sms\SmsDeliveryException;
use App\Contracts\Sms\SmsGateway;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianReceipt;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use Modules\OliveMillAndGrainMill\Models\SianTank;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const OM_API = '/api/v1/olive-mill';

/** A text message gateway that records what it was asked to send. */
final class OmFakeSms implements SmsGateway
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $to, string $message): void
    {
        if ($this->fail) {
            throw new SmsDeliveryException('refused');
        }

        $this->sent[] = [$to, $message];
    }
}

/** A SIAN transport that answers with a fixed receipt. */
final class OmFakeSianTransport implements SianTransport
{
    public int $calls = 0;

    public function __construct(private readonly SianReceipt $receipt) {}

    public function transmit(string $xml, int $operations): SianReceipt
    {
        $this->calls++;

        return $this->receipt;
    }
}

/**
 * Builders of the olive mill tests (Mod 297): an Italian company (its VAT number is the CUAA of the SIAN files), a member signed in with a role, a
 * farmer with a valid fiscal code and a phone number, and a fake SMS gateway. The clock stands on 10 November 2027, in the middle of the campaign.
 */
function omSetUp(object $test, string $role = 'accountant'): void
{
    $test->travelTo('2027-11-10 10:00:00');
    $test->tenant = Tenant::create([
        'name' => 'Frantoio '.strtoupper(substr(md5((string) microtime(true)), 0, 5)),
        'legal_name' => 'Frantoio Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'vat_number' => 'IT01234567897',
    ]);
    $test->otherTenant = Tenant::create(['name' => 'Altro Frantoio', 'legal_name' => 'Altro Frantoio Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    $test->actor = treasuryActor($test, $role);
    $test->sms = new OmFakeSms;
    app()->instance(SmsGateway::class, $test->sms);

    $test->farmer = omFarmer($test);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function omFarmer(object $test, array $overrides = []): Contact
{
    return Contact::factory()->create(array_merge([
        'tenant_id' => $test->tenant->id,
        'company_name' => 'Azienda Agricola Rossi',
        'fiscal_code' => 'RSSMRA85T10A562S',
        'vat_number' => null,
        'phone' => '+393331234567',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function omTank(object $test, array $overrides = []): SianTank
{
    $payload = array_merge(['code' => 'T'.random_int(100, 999), 'capacity_kg' => 5000, 'grid_row' => 1, 'grid_col' => 1], $overrides);
    $id = (string) $test->postJson(OM_API.'/sian-tanks', $payload)->assertCreated()->json('data.id');

    return SianTank::query()->findOrFail($id);
}

/**
 * Weighing of a delivery with a net weight of 4000 kg (4400 gross, 400 of vehicle tare, no crates).
 *
 * @return array<string, mixed>
 */
function omWeighing(float $gross = 4400.0): array
{
    return ['gross_weight_kg' => $gross, 'tare_kg' => 400, 'crate_count' => 0, 'crate_unit_tare_kg' => 0, 'weighbridge_ticket' => 'TKT-1'];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function omDeliveryPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'delivered_at' => '2027-11-10 08:00:00',
        'processing_mode' => 'CONTO_TERZI',
        'commodity' => 'OLIVE',
        'farmer_contact_id' => $test->farmer->id,
        'cultivar' => 'Coratina',
        'certification' => 'EVO',
        'weighing' => omWeighing(),
    ], $overrides);
}

/**
 * A delivery booked through the API (weighed to 4000 kg net by default).
 *
 * @param  array<string, mixed>  $overrides
 */
function omDelivery(object $test, array $overrides = []): OliveDelivery
{
    $id = (string) $test->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($test, $overrides))->assertCreated()->json('data.id');

    return OliveDelivery::query()->findOrFail($id);
}

/**
 * A cycle started for a delivery.
 *
 * @param  array<string, mixed>  $overrides
 */
function omStartCycle(object $test, OliveDelivery $delivery, array $overrides = []): MillingCycle
{
    $id = (string) $test->postJson(OM_API.'/milling-cycles', array_merge(['delivery_id' => $delivery->id, 'line_code' => 'L1', 'malaxation_temperature_c' => 26], $overrides))
        ->assertCreated()->json('data.id');

    return MillingCycle::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function omComplete(object $test, MillingCycle $cycle, array $overrides = []): TestResponse
{
    return $test->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/complete', array_merge(['output_kg' => 600, 'acidity_pct' => 0.4, 'peroxides' => 9], $overrides));
}

/**
 * A third-party cycle completed with 600 kg of oil from 4000 kg of olives: 400 kg picked up, 200 kg left at the mill for the settlement.
 *
 * @param  array<string, mixed>  $overrides
 */
function omCompletedCycle(object $test, array $overrides = [], array $delivery = []): MillingCycle
{
    $cycle = omStartCycle($test, omDelivery($test, $delivery));
    omComplete($test, $cycle, array_merge(['pickup_kg' => 400, 'molenda_kg' => 200, 'mill_stock_kg' => 0], $overrides))->assertOk();

    return $cycle->refresh();
}

/**
 * Pours oil into a tank through an own-account cycle (the whole oil goes to the tank, a C01 load).
 *
 * @param  array<string, mixed>  $delivery
 * @param  array<string, mixed>  $lab
 */
function omStock(object $test, SianTank $tank, float $kg, string $certification = 'EVO', array $delivery = [], array $lab = []): MillingCycle
{
    $gross = $kg * 6 + 400;
    $cycle = omStartCycle($test, omDelivery($test, array_merge([
        'processing_mode' => 'CONTO_PROPRIO', 'farmer_contact_id' => null, 'certification' => $certification, 'weighing' => omWeighing($gross),
    ], $delivery)));
    omComplete($test, $cycle, array_merge(['output_kg' => $kg, 'target_tank_id' => $tank->id], $lab))->assertOk();

    return $cycle->refresh();
}
