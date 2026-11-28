<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;

/**
 * @mixin CourseEnrollment
 */
final class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'initial_class_id' => $this->initial_class_id,
            'contact_id' => $this->contact_id,
            'student_name' => $this->student_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'guardian_name' => $this->guardian_name,
            'guardian_email' => $this->guardian_email,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'bib_number' => $this->bib_number,
            'price_net' => $this->price_net,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'booking_reference' => $this->booking_reference,
            'invoice_id' => $this->invoice_id,
            'addons_invoice_id' => $this->addons_invoice_id,
            'notes' => $this->notes,
            'package_items' => $this->relationLoaded('packageItems') ? PackageItemResource::collection($this->packageItems) : [],
            'attendance' => $this->relationLoaded('attendances') ? $this->attendances->map(static fn ($row): array => ['date' => $row->attendance_date->toDateString(), 'present' => $row->present, 'class_id' => (string) $row->class_id])->values()->all() : [],
            'transfers' => $this->relationLoaded('transfers') ? $this->transfers->map(static fn ($row): array => ['from_class_id' => (string) $row->from_class_id, 'to_class_id' => (string) $row->to_class_id, 'reason' => $row->reason, 'transferred_at' => $row->transferred_at->toIso8601String()])->values()->all() : [],
        ];
    }
}
