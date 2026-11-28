<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The periodic Swiss assessment (Art. 725, 725a, 725b CO): liquidity plan, capital loss and over-indebtedness.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class SwissCo725SolvencyAssessment extends BaseEnterpriseModel
{
    protected $table = 'fin_swiss_co725_assessments';

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'first_negative_month' => 'date',
            'min_cumulative_cash' => 'decimal:2',
            'net_assets' => 'decimal:2',
            'capital_coverage_ratio' => 'decimal:4',
            'net_going_concern' => 'decimal:2',
            'net_liquidation' => 'decimal:2',
            'required_subordination' => 'decimal:2',
            'court_notification_required' => 'boolean',
            'details' => 'array',
        ];
    }
}
