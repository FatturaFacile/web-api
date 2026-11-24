<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceReport
 */
class ServiceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'report_number' => $this->report_number,
            'report_date' => $this->report_date?->toDateString(),
            'status_id' => $this->status_id,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->translation?->label ?? $this->status->code,
            ]),
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name),
                'email' => $this->contact->email,
                'phone' => $this->contact->phone,
            ]),
            'ticket_id' => $this->ticket_id,
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'status' => $this->ticket->status,
            ]),
            'project_id' => $this->project_id,
            'technician_id' => $this->technician_id,
            'technician' => $this->whenLoaded('technician', fn () => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                'email' => $this->technician->email,
            ]),
            'time_start' => $this->time_start,
            'time_end' => $this->time_end,
            'total_hours' => (float) $this->total_hours,
            'travel_distance_km' => (float) $this->travel_distance_km,
            'site_address' => $this->site_address,
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'total_net' => (float) $this->total_net,
            'total_tax' => (float) $this->total_tax,
            'total_gross' => (float) $this->total_gross,
            'is_portal_visible' => (bool) $this->is_portal_visible,
            'signed_at' => $this->signed_at?->toISOString(),
            'invoiced_at' => $this->invoiced_at?->toISOString(),
            'is_signed' => $this->signed_at !== null,
            'is_invoiced' => $this->invoiced_at !== null,
            'invoice_id' => $this->invoiceLink?->invoice_id,
            'lines' => ServiceReportLineResource::collection($this->whenLoaded('lines')),
            'signatures' => ServiceReportSignatureResource::collection($this->whenLoaded('signatures')),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($att): array => [
                'id' => $att->id,
                'original_name' => $att->original_name,
                'mime_type' => $att->mime_type,
                'size' => $att->size,
                'created_at' => $att->created_at?->toISOString(),
            ])->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
