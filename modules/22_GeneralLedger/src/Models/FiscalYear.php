<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $year_code
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $status
 * @property bool $is_locked
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 * @property string|null $reopened_by
 * @property string|null $reopen_reason
 * @property Carbon|null $agm_approved_at
 * @property string|null $agm_approved_by
 * @property string|null $agm_resolution_reference
 */
final class FiscalYear extends BaseEnterpriseModel
{
    protected $table = 'fiscal_years';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_locked' => 'boolean',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'agm_approved_at' => 'datetime',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('period_number');
    }
}
