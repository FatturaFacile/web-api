<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\LipeCommunication;

/**
 * @mixin LipeCommunication
 */
final class LipeCommunicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'quarter' => $this->quarter,
            'status' => $this->status,
            'settlement_ids' => $this->settlement_ids,
            'totals' => $this->totals,
            'file_name' => $this->file_name,
            'xsd_errors' => $this->xsd_errors,
            'reconciliation_status' => $this->reconciliation_status,
            'reconciliation' => $this->reconciliation,
            'signature_status' => $this->signature_status,
            'signature_digest' => $this->signature_digest,
            'signer_fingerprint' => $this->signer_fingerprint,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'transmission_protocol' => $this->transmission_protocol,
            'transmitted_at' => $this->transmitted_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
