<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;

/**
 * @property EmployeeSafetyCertificate $resource
 */
final class SafetyCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $certificate = $this->resource;

        return [
            'id' => $certificate->id,
            'employee_id' => $certificate->employee_id,
            'certificate_type' => $certificate->certificate_type->value,
            'certificate_number' => $certificate->certificate_number,
            'training_provider_name' => $certificate->training_provider_name,
            'hours_completed' => $certificate->hours_completed,
            'issued_at' => $certificate->issued_at->toDateString(),
            'expires_at' => $certificate->expires_at?->toDateString(),
            'document_id' => $certificate->document_id,
            'status' => $certificate->statusAt(CarbonImmutable::today())->value,
        ];
    }
}
