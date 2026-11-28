<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Testing\TestResponse;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const BW_API = '/api/v1/bonded-warehouses';

/**
 * Builders of the bonded warehouse tests (Mod 353): an Italian company with its own contact (same VAT number, the party of the self-invoices), a member
 * signed in with a role, a WMS warehouse and a product. The clock stands on 10 July 2027.
 */
function bwSetUp(object $test, string $role = 'accountant'): void
{
    $test->travelTo('2027-07-10 10:00:00');
    $test->tenant = Tenant::create([
        'name' => 'Deposito '.strtoupper(substr(md5((string) microtime(true)), 0, 5)),
        'legal_name' => 'Deposito Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'vat_number' => 'IT01234567890',
    ]);
    $test->otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    $test->actor = treasuryActor($test, $role);

    $test->warehouse = Warehouse::create(['tenant_id' => $test->tenant->id, 'name' => 'Deposito doganale', 'code' => 'BOND-1', 'is_primary' => false]);
    $test->product = Product::create(['tenant_id' => $test->tenant->id, 'sku' => 'BW-1', 'name' => 'Coffee beans', 'sale_price' => 20, 'tax_rate' => 22, 'cost_price' => '12.5000', 'track_inventory' => true]);
    $test->selfContact = Contact::factory()->create(['tenant_id' => $test->tenant->id, 'vat_number' => 'IT01234567890', 'company_name' => 'Deposito Srl']);
}

/** A valid 18 character MRN: year, country and 14 alphanumerics. */
function bwMrn(int $number): string
{
    return '26IT'.str_pad((string) $number, 14, '0', STR_PAD_LEFT);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bwAuthorizationPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'warehouse_id' => $test->warehouse->id,
        'authorization_code' => 'ADM-'.random_int(1000, 9999),
        'customs_office_code' => 'IT000101',
        'regime_type' => 'CDU_Private_7100',
        'warehouse_subtype' => 'Private',
        'cgu_guarantee_reference' => 'CGU-2027-01',
        'cgu_max_ceiling_amount' => 10000,
        'currency' => 'EUR',
        'aeo_status' => 'AEOC',
        'aeo_certificate_number' => 'AEOC-IT-0001',
        'aeo_next_audit_date' => '2027-12-01',
        'valid_from' => '2027-01-01',
        'valid_to' => '2029-12-31',
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bwAuthorization(object $test, array $overrides = []): CustomsWarehouseAuthorization
{
    $id = (string) $test->postJson(BW_API.'/authorizations', bwAuthorizationPayload($test, $overrides))->assertCreated()->json('data.id');

    return CustomsWarehouseAuthorization::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bwInboundPayload(object $test, CustomsWarehouseAuthorization $authorization, int $mrn, array $overrides = []): array
{
    return array_merge([
        'authorization_id' => $authorization->id,
        'mrn_number' => bwMrn($mrn),
        'mrn_item_number' => 1,
        'declaration_type' => 'IM7',
        'entry_date' => '2027-06-01',
        'product_id' => $test->product->id,
        'taric_hs_code' => '09011100',
        'country_of_origin' => 'BR',
        'initial_quantity' => 100,
        'unit_of_measure' => 'KG',
        'initial_net_weight_kg' => 100,
        'initial_gross_weight_kg' => 105,
        'customs_statistical_value' => 1000,
        'duty_rate_percent' => 10,
        'vat_rate_percent' => 22,
    ], $overrides);
}

/**
 * A bonded lot, registered through the API (value 1000, duty 100, VAT 242, guarantee 342 by default).
 *
 * @param  array<string, mixed>  $overrides
 */
function bwLot(object $test, CustomsWarehouseAuthorization $authorization, int $mrn, array $overrides = []): BondedMrnLot
{
    $id = (string) bwInbound($test, $authorization, $mrn, $overrides)->assertCreated()->json('data.id');

    return BondedMrnLot::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bwInbound(object $test, CustomsWarehouseAuthorization $authorization, int $mrn, array $overrides = []): TestResponse
{
    return $test->postJson(BW_API.'/mrn-lots', bwInboundPayload($test, $authorization, $mrn, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bwFifoDischarge(CustomsWarehouseAuthorization $authorization, object $test, float $quantity, array $overrides = []): array
{
    return array_merge([
        'authorization_id' => $authorization->id,
        'discharge_type' => 'IM4_FreeCirculation',
        'allocation_method' => 'FIFO',
        'discharge_date' => '2027-07-01',
        'product_id' => $test->product->id,
        'quantity' => $quantity,
        'discharge_mrn_number' => bwMrn(900),
    ], $overrides);
}
