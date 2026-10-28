<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReconciliationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'reconciliation_type' => $this->reconciliation_type,
            'period_id' => $this->period_id ? (string) $this->period_id : null,
            'account_id' => (string) $this->account_id,
            'status' => $this->status,
            'gl_balance' => (float) $this->gl_balance,
            'subledger_balance' => (float) $this->subledger_balance,
            'variance_amount' => (float) $this->variance_amount,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
