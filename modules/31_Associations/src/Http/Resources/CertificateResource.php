<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Associations\Models\MedicalCertificate;

/**
 * @mixin MedicalCertificate
 */
final class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->relationLoaded('member') ? $this->member->displayName() : null,
            'discipline_id' => $this->discipline_id,
            'kind' => $this->kind,
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on->toDateString(),
            'is_expired' => $this->expires_on->isPast() && ! $this->expires_on->isToday(),
            'has_file' => $this->path !== null,
            'physician' => $this->physician,
            'notes' => $this->notes,
        ];
    }
}
