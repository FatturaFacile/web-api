<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A sale of inventory or of a fixed asset between two entities of a group, with the margin that is still unrealised
 * for the group at the period end. Amounts are in group currency. The margin is `margin_amount`, or the sale price
 * minus the book cost of the seller. `remaining_percentage` is the share of the goods still in stock (or the asset
 * still held) at the period end; `opening_remaining_percentage` the share at the start of the fiscal year (null: the
 * same, which assumes nothing was sold on during the year). For a fixed asset the unrealised margin is released over
 * `useful_life_years` by straight line, by whole months.
 *
 * @property string $id
 * @property string $group_id
 * @property string|null $relation_id
 * @property string $seller_tenant_id
 * @property string $buyer_tenant_id
 * @property string $asset_type
 * @property Carbon $transaction_date
 * @property string|null $sale_price
 * @property string|null $seller_cost
 * @property string|null $margin_amount
 * @property string $remaining_percentage
 * @property string|null $opening_remaining_percentage
 * @property int|null $useful_life_years
 * @property string $asset_account_id
 * @property string $profit_account_id
 * @property string|null $depreciation_account_id
 */
final class IntragroupTransaction extends BaseEnterpriseModel
{
    protected $table = 'gl_intragroup_transactions';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'sale_price' => 'decimal:4',
            'seller_cost' => 'decimal:4',
            'margin_amount' => 'decimal:4',
            'remaining_percentage' => 'decimal:4',
            'opening_remaining_percentage' => 'decimal:4',
            'useful_life_years' => 'integer',
        ];
    }
}
