<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('period_number');
    }
}
