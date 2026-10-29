<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class FxPosition extends BaseEnterpriseModel
{
    protected $table = 'fx_positions';

    protected function casts(): array
    {
        return [
            'total_cash_holdings' => 'decimal:2',
            'open_ar_exposure' => 'decimal:2',
            'open_ap_exposure' => 'decimal:2',
            'net_exposure' => 'decimal:2',
            'latest_spot_rate' => 'decimal:6',
            'var_95_daily' => 'decimal:2',
            'as_of_date' => 'date',
        ];
    }
}
