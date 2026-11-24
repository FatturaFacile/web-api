<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A dividend that an entity of the group received from another one, in group currency: `income_account_id` is the
 * income account of the receiver that holds it, `equity_account_id` the equity account of the payer it was charged to.
 *
 * @property string $id
 * @property string $group_id
 * @property string $payer_tenant_id
 * @property string $receiver_tenant_id
 * @property Carbon $dividend_date
 * @property string $amount
 * @property string $income_account_id
 * @property string $equity_account_id
 */
final class IntragroupDividend extends BaseEnterpriseModel
{
    protected $table = 'gl_intragroup_dividends';

    protected function casts(): array
    {
        return ['dividend_date' => 'date', 'amount' => 'decimal:4'];
    }
}
