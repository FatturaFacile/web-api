<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A shareholder or third-party loan under a subordination agreement (Rangrücktritt, Art. 725b CO).
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class SubordinatedLoanRangruecktritt extends BaseEnterpriseModel
{
    protected $table = 'fin_subordinated_loans_rangruecktritt';

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'agreement_date' => 'date',
            'effective_from' => 'date',
            'deferral_end_date' => 'date',
        ];
    }
}
