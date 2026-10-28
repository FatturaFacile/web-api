<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'line_number' => (int) $this->line_number,
            'account_id' => (string) $this->account_id,
            'cost_center_id' => $this->cost_center_id ? (string) $this->cost_center_id : null,
            'profit_center_id' => $this->profit_center_id ? (string) $this->profit_center_id : null,
            'project_id' => $this->project_id ? (string) $this->project_id : null,
            'partner_type' => $this->partner_type,
            'partner_id' => $this->partner_id ? (string) $this->partner_id : null,
            'description' => $this->description,
            'debit_amount' => (float) $this->debit_amount,
            'credit_amount' => (float) $this->credit_amount,
            'currency' => $this->currency,
            'exchange_rate' => (float) $this->exchange_rate,
            'amount_in_functional_currency' => (float) $this->amount_in_functional_currency,
        ];
    }
}
