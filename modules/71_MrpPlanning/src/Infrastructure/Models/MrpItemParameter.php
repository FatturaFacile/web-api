<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;

/**
 * Planning parameters of an item that the manufacturing item does not carry: lot-sizing rule, EOQ, fixed days of supply,
 * supplier country (customs) and customs clearance days.
 *
 * @property string $mfg_item_id
 * @property LotSizingRule $lot_sizing_rule
 * @property string $eoq_quantity
 * @property int $fixed_days_of_supply
 * @property string|null $supplier_id
 * @property string|null $supplier_country
 * @property int|null $customs_clearance_days
 */
final class MrpItemParameter extends BaseEnterpriseModel
{
    protected $table = 'mrp_item_parameters';

    protected function casts(): array
    {
        return [
            'lot_sizing_rule' => LotSizingRule::class,
            'eoq_quantity' => 'decimal:4',
            'fixed_days_of_supply' => 'integer',
            'customs_clearance_days' => 'integer',
        ];
    }
}
