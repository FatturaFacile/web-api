<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SalesBoundedContext\Domain\Enums\CustomerHierarchyRole;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;

final class SbcCustomerDossier extends BaseEnterpriseModel
{
    protected $table = 'sbc_customer_dossiers';

    protected function casts(): array
    {
        return [
            'hierarchy_role' => CustomerHierarchyRole::class,
            'insolvency_status' => InsolvencyRiskStatus::class,
            'addresses' => 'array',
            'contacts' => 'array',
            'summary_360' => 'array',
            'credit_limit' => 'decimal:2',
            'current_exposure' => 'decimal:2',
            'overdue_balance' => 'decimal:2',
            'risk_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentDossier(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_dossier_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function subsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_dossier_id');
    }
}
