<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The periodic Italian assessment (CCII, Art. 2086 c.c.): DSCR, the seven early-warning indicators, the public creditor monitor and the CNC test.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class AdequateOrganizationalAssetReportCcii extends BaseEnterpriseModel
{
    protected $table = 'fin_ccii_crisis_assessments';

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'dscr_6m_treasury' => 'decimal:2',
            'dscr_12m_treasury' => 'decimal:2',
            'dscr_6m_operating' => 'decimal:2',
            'dscr_12m_operating' => 'decimal:2',
            'indicators' => 'array',
            'public_creditors' => 'array',
            'cnc_test' => 'array',
        ];
    }
}
