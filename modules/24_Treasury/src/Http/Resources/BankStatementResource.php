<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\BankStatement;

/**
 * @mixin BankStatement
 */
final class BankStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'statement_number' => $this->statement_number,
            'statement_date' => $this->statement_date?->toDateString(),
            'format' => $this->format,
            'opening_balance' => (float) $this->opening_balance,
            'closing_balance' => (float) $this->closing_balance,
            'total_debits' => (float) $this->total_debits,
            'total_credits' => (float) $this->total_credits,
            'lines_count' => (int) $this->lines_count,
            'reconciliation_status' => $this->reconciliation_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
