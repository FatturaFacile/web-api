<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Support\TenantNames;

/**
 * @mixin TransactionLink
 */
final class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'pair_id' => (string) $this->pair_id,
            'group_id' => (string) $this->group_id,
            'document_type' => $this->document_type,
            'source_tenant_id' => (string) $this->source_tenant_id,
            'source_tenant_name' => TenantNames::of((string) $this->source_tenant_id),
            'source_document_id' => (string) $this->source_document_id,
            'source_document_number' => $this->source_document_number,
            'mirror_tenant_id' => (string) $this->mirror_tenant_id,
            'mirror_tenant_name' => TenantNames::of((string) $this->mirror_tenant_id),
            'mirror_document_id' => $this->mirror_document_id,
            'mirror_document_number' => $this->mirror_document_number,
            'mirror_status' => $this->mirror_status,
            'status' => $this->status,
            'currency' => $this->currency,
            'net_amount' => (string) $this->net_amount,
            'tax_amount' => (string) $this->tax_amount,
            'gross_amount' => (string) $this->gross_amount,
            'payment_status' => $this->payment_status,
            'synced_paid_amount' => (string) $this->synced_paid_amount,
            'paid_variance' => (string) $this->paid_variance,
            'document_date' => $this->document_date->toDateString(),
            'error_message' => $this->error_message,
            'version' => (int) $this->version,
            'idempotency_key' => $this->idempotency_key,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
