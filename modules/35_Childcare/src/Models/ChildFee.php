<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fee plan of a child for a period.
 */
final class ChildFee extends ChildcareRecord
{
    protected $table = 'cc_child_fees';

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<FeePlan, $this>
     */
    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }
}
