<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Closing, average or historical rate fixed for a group and a period end; wins over the published exchange rates.
 *
 * @property Carbon $period_end
 * @property string $rate
 */
final class PeriodRate extends BaseEnterpriseModel
{
    protected $table = 'ic_period_rates';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'rate' => 'decimal:8',
        ];
    }
}
