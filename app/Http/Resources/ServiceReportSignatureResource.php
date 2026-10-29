<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceReportSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceReportSignature
 */
class ServiceReportSignatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signer_type' => $this->signer_type,
            'signer_name' => $this->signer_name,
            'signer_role' => $this->signer_role,
            'signer_email' => $this->signer_email,
            'signature_hash' => $this->signature_hash,
            'signed_at' => $this->signed_at?->toISOString(),
            'ip_address' => $this->ip_address,
        ];
    }
}
