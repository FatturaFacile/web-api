<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Physiological weight loss curve by cheese type and age. A null tenant is a shared reference curve.
 *
 * @property string $cheese_type
 * @property string $name
 * @property array<int,array{age_months:float|int|string,loss_pct:float|int|string}> $points
 */
final class WeightLossCurve extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'dairy_weight_loss_curves';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'array',
        ];
    }
}
