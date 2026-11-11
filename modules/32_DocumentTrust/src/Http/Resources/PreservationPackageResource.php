<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationVerification;

/**
 * API representation of a preservation package. The manifest is downloaded through the export, not embedded.
 *
 * @mixin PreservationPackage
 */
final class PreservationPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = $this->resource->relationLoaded('latestVerification') ? $this->resource->latestVerification : null;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'document_class' => $this->document_class,
            'status' => $this->status,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'items_count' => (int) $this->items_count,
            'total_bytes' => (int) $this->total_bytes,
            'retention_years' => $this->retention_years,
            'retention_source' => $this->retention_source,
            'retention_until' => $this->retention_until?->toDateString(),
            'package_hash' => $this->package_hash,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'timestamp_provider' => $this->timestamp_provider,
            'timestamp_token' => $this->timestamp_token,
            'timestamp_qualified' => (bool) $this->timestamp_qualified,
            'timestamped_at' => $this->timestamped_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_verification' => $verification instanceof PreservationVerification ? new PreservationVerificationResource($verification) : null,
        ];
    }
}
