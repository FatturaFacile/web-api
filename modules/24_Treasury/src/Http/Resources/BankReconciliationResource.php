<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\BankReconciliation;

/**
 * @mixin BankReconciliation
 */
final class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'reconciliation_number' => $this->reconciliation_number,
            'period_start_date' => $this->period_start_date?->toDateString(),
            'period_end_date' => $this->period_end_date?->toDateString(),
            'bank_statement_balance' => (float) $this->bank_statement_balance,
            'gl_book_balance' => (float) $this->gl_book_balance,
            'difference_amount' => (float) $this->difference_amount,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
