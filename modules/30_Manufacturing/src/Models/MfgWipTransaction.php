<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of the work-in-progress account of a production order: positive amounts add to WIP (material issue,
 * labour and overhead absorption, subcontracting), negative amounts relieve it (output receipt, variance settlement).
 * Written together with the journal entry, so the sum per order always equals the WIP balance of the order.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $production_order_id
 * @property string $transaction_type material|conversion|subcontract|output|variance
 * @property string $amount
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $journal_entry_id
 * @property Carbon $posting_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgWipTransaction extends BaseEnterpriseModel
{
    protected $table = 'mfg_wip_transactions';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'posting_date' => 'date'];
    }

    /**
     * @return BelongsTo<MfgProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }
}
