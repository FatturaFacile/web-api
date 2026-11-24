<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Models\JournalEntry;

final class FxRevaluation extends BaseEnterpriseModel
{
    protected $table = 'fx_revaluations';

    protected function casts(): array
    {
        return [
            'period_end_date' => 'date',
            'foreign_amount' => 'decimal:2',
            'book_value_base' => 'decimal:2',
            'revalued_market_base' => 'decimal:2',
            'unrealized_gain_loss' => 'decimal:2',
        ];
    }

    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }

    public function getSpotRateAttribute(): float
    {
        return (float) $this->foreign_amount > 0 ? round((float) $this->revalued_market_base / (float) $this->foreign_amount, 4) : 1.0;
    }

    public function getGainOrLossAttribute(): string
    {
        $diff = (float) $this->unrealized_gain_loss;

        return $diff > 0.001 ? 'GAIN' : ($diff < -0.001 ? 'LOSS' : 'NEUTRAL');
    }
}
