<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\ReconciliationItem;
use Modules\Intercompany\Support\TenantNames;

/**
 * @mixin ReconciliationItem
 */
final class ReconciliationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'batch_id' => (string) $this->batch_id,
            'entity_a_tenant_id' => (string) $this->entity_a_tenant_id,
            'entity_a_name' => TenantNames::of((string) $this->entity_a_tenant_id),
            'entity_b_tenant_id' => (string) $this->entity_b_tenant_id,
            'entity_b_name' => TenantNames::of((string) $this->entity_b_tenant_id),
            'a_document_id' => $this->a_document_id,
            'a_document_number' => $this->a_document_number,
            'a_document_date' => $this->a_document_date?->toDateString(),
            'a_currency' => $this->a_currency,
            'a_amount' => $this->a_amount === null ? null : (string) $this->a_amount,
            'a_tax' => $this->a_tax === null ? null : (string) $this->a_tax,
            'b_document_id' => $this->b_document_id,
            'b_document_number' => $this->b_document_number,
            'b_document_date' => $this->b_document_date?->toDateString(),
            'b_currency' => $this->b_currency,
            'b_amount' => $this->b_amount === null ? null : (string) $this->b_amount,
            'b_tax' => $this->b_tax === null ? null : (string) $this->b_tax,
            'difference' => (string) $this->difference,
            'difference_group_currency' => (string) $this->difference_group_currency,
            'status' => $this->status,
            'anomaly_type' => $this->anomaly_type,
            'anomaly_reason' => $this->anomaly_reason,
            'match_kind' => $this->match_kind,
            'resolution_reason' => $this->resolution_reason,
            'adjustment_amount' => $this->adjustment_amount === null ? null : (string) $this->adjustment_amount,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
