<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use Modules\IndustrialLaundry\Models\Garment;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * A company with a finance user signed in (owners, administrators and accountants write and bill), a customer and another
 * customer of the same company.
 */
function ilrSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Lavanderia Alfa', 'legal_name' => 'Lavanderia Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->actor = treasuryActor($test, 'accountant');
    $test->customer = Contact::query()->create(['company_name' => 'Hotel Bellavista', 'type' => 'customer', 'country' => 'IT']);
    $test->otherCustomer = Contact::query()->create(['company_name' => 'RSA Villa Serena', 'type' => 'customer', 'country' => 'IT']);
}

function ilrEpc(int $number): string
{
    return sprintf('E2801160%016X', $number);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ilrGarment(object $test, int $number, array $attributes = []): Garment
{
    return Garment::query()->create($attributes + [
        'tenant_id' => $test->tenant->id,
        'epc_uid_rfid_hex' => ilrEpc($number),
        'article_type' => 'flat_linen',
        'assigned_customer_id' => $test->customer->id,
        'current_lifecycle_state' => 'delivered_to_customer',
        'wash_cycles_count' => 0,
    ]);
}

/**
 * @param  list<int>  $numbers  Garments whose tag is read at the gate.
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ilrScanPayload(array $numbers, array $overrides = []): array
{
    return array_merge([
        'gate_antenna_id' => 'GATE-01',
        'roll_cage_barcode' => 'RC-0001',
        'direction' => 'inbound_soil',
        'reads' => array_map(static fn (int $number): array => ['epc' => ilrEpc($number), 'rssi' => -58.0], $numbers),
        'gross_weight_kg' => '118.5',
        'roll_cage_tare_weight_kg' => '38.5',
    ], $overrides);
}
