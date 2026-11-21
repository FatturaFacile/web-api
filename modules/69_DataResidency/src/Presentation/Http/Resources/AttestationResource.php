<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;

/**
 * @mixin SovereigntyComplianceAttestation
 */
final class AttestationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'certificate_number' => $this->certificate_number,
            'jurisdiction_zone' => $this->jurisdiction_zone,
            'compliance_frameworks' => $this->compliance_frameworks_json,
            'sha256_payload_hash' => $this->sha256_payload_hash,
            'signer_key_fingerprint' => $this->signer_key_fingerprint,
            'issued_at' => $this->issued_at->toIso8601String(),
            'valid_until' => $this->valid_until->toIso8601String(),
        ];
    }
}
