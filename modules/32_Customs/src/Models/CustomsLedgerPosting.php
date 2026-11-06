<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Customs\Enums\LedgerPostingStatus;

final class CustomsLedgerPosting extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'status' => LedgerPostingStatus::class,
            'posting_date' => 'date',
            'duty_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CustomsAssessmentDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(CustomsAssessmentDecision::class, 'assessment_decision_id');
    }
}
