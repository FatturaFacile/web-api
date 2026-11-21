<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `fact_commercial_velocity` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class FactCommercialVelocity extends BaseEnterpriseModel
{
    protected $table = 'fact_commercial_velocity';

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'paid_date' => 'date', 'amount_net' => 'decimal:4', 'amount_open' => 'decimal:4'];
    }
}
