<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_number' => $this->appointment_number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->translations->firstWhere('locale', app()->getLocale())?->label
                    ?? $this->status->translations->firstWhere('locale', 'en')?->label
                    ?? $this->status->code,
            ]),
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => $this->contact->company_name ?? trim($this->contact->first_name.' '.$this->contact->last_name),
                'email' => $this->contact->email,
                'phone' => $this->contact->phone,
            ]),
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'priority' => $this->ticket->priority,
                'status' => $this->ticket->status,
            ]),
            'service_report_id' => $this->service_report_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'all_day' => $this->all_day,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'estimated_travel_minutes' => $this->estimated_travel_minutes,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'technician_notes' => $this->technician_notes,
            'customer_notes' => $this->customer_notes,
            'is_portal_visible' => $this->is_portal_visible,
            'reminder_sent_at' => $this->reminder_sent_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'attendees' => AppointmentAttendeeResource::collection($this->whenLoaded('attendees')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
