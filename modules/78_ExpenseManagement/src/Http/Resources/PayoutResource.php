<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\ExpensePayout;

/**
 * @mixin ExpensePayout
 */
final class PayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_report_id' => $this->expense_report_id,
            'channel' => $this->channel,
            'status' => $this->status,
            'message_id' => $this->message_id,
            'total_amount' => $this->total_amount,
            'taxable_amount' => $this->taxable_amount,
            'exempt_amount' => $this->exempt_amount,
            'currency' => $this->currency,
            'execution_date' => $this->execution_date?->toDateString(),
            'file_name' => $this->file_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
