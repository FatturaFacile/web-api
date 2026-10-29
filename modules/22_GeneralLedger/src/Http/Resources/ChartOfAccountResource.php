<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'account_code' => $this->account_code,
            'name' => $this->name,
            'account_class' => $this->account_class,
            'account_type' => $this->account_type,
            'normal_balance' => $this->normal_balance,
            'currency' => $this->currency,
            'is_reconciliation_account' => (bool) $this->is_reconciliation_account,
            'reconciliation_type' => $this->reconciliation_type,
            'allows_manual_posting' => (bool) $this->allows_manual_posting,
            'is_cost_center_mandatory' => (bool) $this->is_cost_center_mandatory,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
