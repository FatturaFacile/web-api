<?php

declare(strict_types=1);

namespace Modules\Logistics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Logistics\Concerns\HasAuditUsers;

/**
 * Contract governing goods held by a third party (customer consignment / "conto deposito") or goods of a vendor held by
 * us (vendor consignment): who the partner is, where the stock sits and how often consumption is declared and billed.
 */
final class ConsignmentAgreement extends BaseEnterpriseModel
{
    use HasAuditUsers;

    protected $table = 'consignment_agreements';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'payment_terms_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<ConsignmentConsumptionDeclaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(ConsignmentConsumptionDeclaration::class, 'agreement_id');
    }
}
