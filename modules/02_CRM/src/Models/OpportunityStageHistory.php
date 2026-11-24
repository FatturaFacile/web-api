<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail row of an opportunity moving between stages, with the amounts at that moment and, on a loss, the
 * structured loss data used for retrospective BI.
 */
final class OpportunityStageHistory extends BaseEnterpriseModel
{
    protected $table = 'crm_opportunity_stage_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'probability' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<OpportunityStage, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(OpportunityStage::class, 'from_stage_id');
    }

    /**
     * @return BelongsTo<OpportunityStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(OpportunityStage::class, 'to_stage_id');
    }
}
