<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarbonLedger extends BaseEnterpriseModel
{
    protected $table = 'carbon_ledger';

    protected function casts(): array
    {
        return [
            'activity_data_metric' => 'decimal:4',
            'emission_factor' => 'decimal:6',
            'total_co2e_kg' => 'decimal:4',
            'posting_date' => 'date',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
