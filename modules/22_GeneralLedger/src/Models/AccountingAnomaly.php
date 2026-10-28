<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccountingAnomaly extends BaseEnterpriseModel
{
    protected $table = 'accounting_anomalies';

    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'detected_at' => 'datetime',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
