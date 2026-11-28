<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Models\TicketParticipant;
use Modules\AdventureParks\Models\TicketPpeAssignment;

/**
 * @mixin Ticket
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'public_token' => $this->public_token,
            'slot_date' => $this->slot_date->toDateString(),
            'slot' => [
                'id' => $this->slot_template_id,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'wristband_color' => $this->wristband_color,
            ],
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'headcount' => $this->headcount,
            'lines' => $this->lines->map(fn ($line): array => ['size' => $line->size, 'quantity' => $line->quantity])->values()->all(),
            'participants' => $this->participants->map(fn (TicketParticipant $participant): array => [
                'id' => $participant->id,
                'full_name' => $participant->full_name,
                'is_minor' => $participant->is_minor,
                'birth_date' => $participant->birth_date?->toDateString(),
                'height_cm' => $participant->height_cm === null ? null : (float) $participant->height_cm,
                'weight_kg' => $participant->weight_kg === null ? null : (float) $participant->weight_kg,
                'size' => $participant->size,
                'assignments' => $participant->assignments->map(fn (TicketPpeAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'ppe_id' => $assignment->ppe_asset_id,
                    'serial_code' => $assignment->asset?->serial_code,
                    'category' => $assignment->asset?->category,
                    'assigned_at' => $assignment->assigned_at->toIso8601String(),
                    'returned_at' => $assignment->returned_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'wristband_color' => $this->wristband_color,
            'payment_status' => $this->payment_status,
            'status' => $this->status,
            'waiver_signed' => $this->waiver !== null,
            'waiver_url_path' => '/waiver/'.$this->public_token,
            'briefing_completed' => $this->waiver?->briefing_completed_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
