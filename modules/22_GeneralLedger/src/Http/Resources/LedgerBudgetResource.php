<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\LedgerBudgetLine;

final class LedgerBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fiscal_year_id' => (string) $this->fiscal_year_id,
            'name' => $this->name,
            'version' => (int) $this->version,
            'status' => $this->status,
            'notes' => $this->notes,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn (LedgerBudgetLine $line): array => [
                'account_id' => (string) $line->account_id,
                'cost_center_id' => $line->cost_center_id,
                'period_number' => (int) $line->period_number,
                'amount' => (string) $line->amount,
            ])->all()),
        ];
    }
}
