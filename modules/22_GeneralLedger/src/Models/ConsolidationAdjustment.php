<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\GeneralLedger\Enums\ConsolidationAdjustmentType;

/**
 * An elimination or translation adjustment of a run: a balanced set of lines in group currency. It is not a journal
 * entry: it never reaches the ledger of any entity.
 *
 * @property string $id
 * @property ConsolidationAdjustmentType $adjustment_type
 * @property string $amount
 */
final class ConsolidationAdjustment extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_adjustments';

    protected function casts(): array
    {
        return [
            'adjustment_type' => ConsolidationAdjustmentType::class,
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return HasMany<ConsolidationAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidationAdjustmentLine::class, 'adjustment_id')->orderBy('line_number');
    }
}
