<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The settlement of the accrued rebate: a credit note draft to the customer (sales) or a claim to the supplier
 * (purchases).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $amount
 * @property string $currency
 * @property string $settlement_type
 * @property string|null $credit_note_id
 * @property Carbon $settled_on
 * @property string|null $notes
 */
final class RebateSettlement extends BaseEnterpriseModel
{
    public const string CreditNoteDraft = 'credit_note_draft';

    public const string SupplierClaim = 'supplier_claim';

    protected $table = 'rebate_settlements';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_on' => 'date'];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }

    /**
     * @return HasMany<RebateAccrual, $this>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(RebateAccrual::class, 'settlement_id');
    }
}
