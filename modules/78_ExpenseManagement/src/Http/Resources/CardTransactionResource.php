<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * @mixin CorporateCardTransaction
 */
final class CardTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_account_id' => $this->card_account_id,
            'merchant_name' => $this->merchant_name,
            'transaction_date' => $this->transaction_date->toDateString(),
            'posting_date' => $this->posting_date?->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'card_last4' => $this->whenLoaded('account', fn (): string => $this->account->card_last4),
            'holder_name' => $this->whenLoaded('account', fn (): string => $this->account->holder_name),
            'status' => $this->status->value,
            'matched_amount' => $this->matched_amount,
            'remaining_amount' => Decimal::sub($this->amount, $this->matched_amount),
            'matched_lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'expense_report_id' => $line->expense_report_id,
                'report_number' => $line->report?->report_number,
                'amount' => $line->amount_in_base_currency,
            ])->all()),
        ];
    }
}
