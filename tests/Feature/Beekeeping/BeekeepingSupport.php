<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\Hive;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const BEE_API = '/api/v1/beekeeping';

/**
 * An Italian beekeeping company with an accountant signed in. The clock stands on Monday 15 June 2026.
 */
function beeSetUp(object $test): void
{
    $test->travelTo('2026-06-15 09:00:00');
    $test->tenant = Tenant::create(['name' => 'Apicoltura Rossi', 'legal_name' => 'Apicoltura Rossi Srl', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Apicoltura', 'legal_name' => 'Altra Apicoltura Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->accountant = treasuryActor($test, 'accountant');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function beeApiaryPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'AP-001',
        'name' => 'Prato Fiorito',
        'country' => 'IT',
        'municipality' => 'Bergamo',
        'province_canton' => 'BG',
        'registry_code' => '012BG123',
        'apiary_type' => 'stationary',
        'latitude' => 45.6983,
        'longitude' => 9.6773,
        'altitude_m' => 250,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function beeApiary(object $test, array $overrides = []): Apiary
{
    $id = $test->postJson(BEE_API.'/apiaries', beeApiaryPayload($overrides))->assertCreated()->json('data.id');

    return treasuryInTenant($test->tenant, fn (): Apiary => Apiary::query()->findOrFail($id));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function beeHive(object $test, Apiary $apiary, string $code, array $overrides = []): Hive
{
    $payload = array_merge(['apiary_id' => $apiary->id, 'code' => $code, 'hive_type' => 'dadant_blatt', 'queen_year' => 2025], $overrides);
    $id = $test->postJson(BEE_API.'/hives', $payload)->assertCreated()->json('data.id');

    return treasuryInTenant($test->tenant, fn (): Hive => Hive::query()->findOrFail($id));
}

/**
 * A veterinary treatment of a whole apiary (or of some hives) with a withdrawal period.
 *
 * @param  array<string, mixed>  $overrides
 */
function beeTreatment(object $test, Apiary $apiary, array $overrides = []): string
{
    $payload = array_merge([
        'apiary_id' => $apiary->id,
        'treatment_date' => '2026-06-10',
        'product' => 'amitraz',
        'drug_batch' => 'LOT-77',
        'prescription_ref' => 'RX-2026-1',
        'veterinarian' => 'Dott. Bianchi',
    ], $overrides);

    return (string) $test->postJson(BEE_API.'/varroa/treatments', $payload)->assertCreated()->json('data.id');
}
