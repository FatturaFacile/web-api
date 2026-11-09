<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\Accrual;

/**
 * @mixin Accrual
 */
final class AccrualResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->relationLoaded('journalEntry') ? $this->journalEntry : null;

        return [
            'id' => (string) $this->id,
            'accrual_type' => $this->accrual_type,
            'description' => $this->description,
            'total_amount' => (string) $this->total_amount,
            'coverage_start' => LedgerDateFormat::day($this->coverage_start),
            'coverage_end' => LedgerDateFormat::day($this->coverage_end),
            'cutoff_date' => LedgerDateFormat::day($this->cutoff_date),
            'coverage_days' => $this->coverage_days,
            'accrued_days' => $this->accrued_days,
            'amount' => (string) $this->amount,
            'income_statement_account_id' => (string) $this->income_statement_account_id,
            'balance_sheet_account_id' => (string) $this->balance_sheet_account_id,
            'cost_center_id' => $this->cost_center_id,
            'journal_entry_id' => $this->journal_entry_id,
            'journal_status' => $entry?->status,
            'reversal_date' => LedgerDateFormat::day($this->reversal_date),
            'reversal_journal_entry_id' => $entry !== null && $entry->status === 'reversed' ? $entry->reversal_journal_entry_id : null,
            'created_by' => $this->created_by,
            'created_at' => LedgerDateFormat::instant($this->created_at),
        ];
    }
}
