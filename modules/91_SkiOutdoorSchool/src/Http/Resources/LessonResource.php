<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * @mixin SkiLesson
 */
final class LessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'instructor_id' => $this->instructor_id,
            'instructor_name' => $this->relationLoaded('instructor') ? $this->instructor?->fullName() : null,
            'requested_instructor_id' => $this->requested_instructor_id,
            'is_nominated' => $this->is_nominated,
            'assignment_method' => $this->assignment_method->value,
            'discipline' => $this->discipline->value,
            'language_code' => $this->language_code,
            'level_id' => $this->level_id,
            'lesson_date' => $this->lesson_date->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'slot_minutes' => $this->slot_minutes,
            'slot_count' => $this->slot_count,
            'duration_minutes' => $this->duration_minutes,
            'participants' => $this->participants,
            'customer_contact_id' => $this->customer_contact_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'meeting_point' => $this->meeting_point,
            'price_net' => $this->price_net,
            'vat_regime' => $this->vat_regime->value,
            'vat_legal_reference' => $this->vat_regime->legalReference(),
            'status' => $this->status->value,
            'source' => $this->source->value,
            'invoice_id' => $this->invoice_id,
            'addons_invoice_id' => $this->addons_invoice_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'package_items' => $this->relationLoaded('packageItems') ? PackageItemResource::collection($this->packageItems) : [],
        ];
    }
}
