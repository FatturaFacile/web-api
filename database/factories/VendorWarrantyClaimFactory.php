<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

/**
 * @extends Factory<VendorWarrantyClaim>
 */
class VendorWarrantyClaimFactory extends Factory
{
    protected $model = VendorWarrantyClaim::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rma_order_id' => DepotRepairRmaOrder::factory(),
            'tenant_id' => fn (array $attributes) => DepotRepairRmaOrder::withoutGlobalScopes()->find($attributes['rma_order_id'])?->tenant_id,
            'original_supplier_id' => (string) Str::uuid7(),
            'defective_part_serial' => 'DEF-'.strtoupper(Str::random(6)),
            'status' => 'draft',
            'claimed_amount' => 30.00,
            'currency' => 'EUR',
        ];
    }
}
