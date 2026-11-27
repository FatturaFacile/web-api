<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Distribution\Domain\Enums\BillingFrequency;
use Modules\Distribution\Domain\Enums\SettlementTrigger;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Inventory\Models\Warehouse;

/**
 * Network profile of a warehouse: its role (internal, point of sale, transit, 3PL, consignment), the hub it is supplied by, the
 * default in-transit warehouse, the third party behind it and the consignment terms.
 *
 * @property string $warehouse_id
 * @property WarehouseRole $role
 * @property string|null $parent_warehouse_id
 * @property string|null $default_transit_warehouse_id
 * @property string|null $third_party_partner_id
 * @property string|null $external_reference
 * @property SettlementTrigger|null $settlement_trigger
 * @property BillingFrequency|null $billing_frequency
 * @property int|null $max_consignment_days
 * @property bool $is_insured
 * @property bool $counts_in_valuation
 * @property bool $include_in_free_stock
 * @property bool $is_blocked
 * @property bool $is_counting
 * @property string|null $notes
 */
final class WarehouseProfile extends BaseEnterpriseModel
{
    protected $table = 'distribution_warehouse_profiles';

    protected function casts(): array
    {
        return [
            'role' => WarehouseRole::class,
            'settlement_trigger' => SettlementTrigger::class,
            'billing_frequency' => BillingFrequency::class,
            'max_consignment_days' => 'integer',
            'is_insured' => 'boolean',
            'counts_in_valuation' => 'boolean',
            'include_in_free_stock' => 'boolean',
            'is_blocked' => 'boolean',
            'is_counting' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
