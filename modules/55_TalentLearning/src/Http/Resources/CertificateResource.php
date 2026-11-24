<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\LmsCertificate;

/**
 * A completion certificate.
 *
 * @mixin LmsCertificate
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
            'enrolment_id' => $this->enrolment_id,
            'course_id' => $this->course_id,
            'course' => $this->whenLoaded('course', fn (): array => ['id' => $this->course->id, 'code' => $this->course->code, 'title' => $this->course->title]),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee'),
            'certificate_number' => $this->certificate_number,
            'issued_on' => $this->issued_on->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'is_valid' => $this->expires_on === null || $this->expires_on->greaterThanOrEqualTo(today()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
