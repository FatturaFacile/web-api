<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Logistics\Concerns\HasAuditUsers;

/**
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
final class ConsignmentConsumptionDeclaration extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'consignment_consumption_declarations';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ConsignmentConsumptionDeclarationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentConsumptionDeclarationLine::class, 'declaration_id');
    }
}
