<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankStatement
 */
class BankStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_format' => $this->source_format,
            'account_iban' => $this->account_iban,
            'statement_date' => $this->statement_date->toDateString(),
            'opening_balance' => $this->opening_balance === null ? null : (float) $this->opening_balance,
            'closing_balance' => $this->closing_balance === null ? null : (float) $this->closing_balance,
            'currency' => $this->currency,
            'total_entries' => $this->total_entries,
            'status' => $this->status,
            'reconciled_entries' => $this->whenCounted('reconciled_lines'),
            'lines' => $this->whenLoaded('lines', fn () => BankStatementLineResource::collection($this->lines)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
