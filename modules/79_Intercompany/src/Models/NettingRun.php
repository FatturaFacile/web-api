<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A proposal or application of the netting of the open intercompany items of two entities.
 *
 * @property Carbon $as_of_date
 * @property Carbon|null $confirmed_at
 * @property int $version
 * @property string $receivable_total
 * @property string $payable_total
 * @property string $net_amount
 * @property string $fx_variance
 * @property string $amount_variance
 */
final class NettingRun extends BaseEnterpriseModel
{
    protected $table = 'ic_netting_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'receivable_total' => 'decimal:4',
            'payable_total' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'fx_variance' => 'decimal:4',
            'amount_variance' => 'decimal:4',
            'version' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
