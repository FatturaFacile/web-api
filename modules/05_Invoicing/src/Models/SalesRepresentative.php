<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesRepresentative extends BaseEnterpriseModel
{
    protected $table = 'sales_representatives';

    protected function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(SalesCommission::class, 'sales_representative_id');
    }
}
