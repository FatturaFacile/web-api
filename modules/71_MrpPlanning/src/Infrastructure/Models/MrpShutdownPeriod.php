<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A closure period of a supplier, or of all suppliers (of a country) when supplier_id is null (Ferragosto, Swiss factory holidays).
 *
 * @property string|null $supplier_id
 * @property string $label
 * @property string|null $country
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
final class MrpShutdownPeriod extends BaseEnterpriseModel
{
    protected $table = 'mrp_shutdown_periods';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
