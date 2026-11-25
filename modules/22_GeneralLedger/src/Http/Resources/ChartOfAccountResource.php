<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\ChartOfAccount;

final class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accountCode = $this->account_code;
        $displayName = $this->name;
        /** @var ChartOfAccount $model */
        $model = $this->resource;

        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'company_id' => $model->company_id !== null ? (string) $model->company_id : null,
            'account_code' => $accountCode,
            'name' => $displayName,
            'account_name' => $displayName,
            'description' => $model->description,
            'account_class' => $this->account_class,
            'account_type' => $this->account_type,
            'normal_balance' => $this->normal_balance,
            'parent_account_id' => $model->parent_account_id !== null ? (string) $model->parent_account_id : null,
            'level' => (int) $model->level,
            'hierarchy_path' => $model->hierarchy_path ?? $accountCode,
            'currency' => $this->currency,
            'vat_code_id' => $model->vat_code_id !== null ? (string) $model->vat_code_id : null,
            'vat_code' => $this->whenLoaded('vatCode', fn () => $model->vatCode?->getAttribute('code')),
            'default_cost_center_id' => $model->default_cost_center_id !== null ? (string) $model->default_cost_center_id : null,
            'default_cost_center_code' => $this->whenLoaded('defaultCostCenter', fn () => $model->defaultCostCenter?->getAttribute('code')),
            'open_items_enabled' => (bool) $model->open_items_enabled,
            'allowed_currencies' => $model->allowed_currencies ?? [],
            'is_postable' => (bool) $model->is_postable,
            'is_reconciliation_account' => (bool) $this->is_reconciliation_account,
            'reconciliation_type' => $this->reconciliation_type,
            'allows_manual_posting' => (bool) $this->allows_manual_posting,
            'is_cost_center_mandatory' => (bool) $this->is_cost_center_mandatory,
            'is_system' => (bool) $model->is_system,
            'is_active' => (bool) $this->is_active,
            'template_code' => $model->template_code,
            'statutory_code' => $model->statutory_code,
            'reporting_class' => $this->reporting_class,
            'outgoing_mappings' => AccountMappingResource::collection($this->whenLoaded('outgoingMappings')),
            'incoming_mappings' => AccountMappingResource::collection($this->whenLoaded('incomingMappings')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
