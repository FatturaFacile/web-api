<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Logistics\Concerns\HasAuditUsers;

final class ConsignmentConsumptionReport extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'consignment_consumption_reports';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentConsumptionLine::class, 'report_id');
    }
}
