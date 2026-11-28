<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\AdventureParks\Models\SchoolBookingWorkshop;

/**
 * @mixin SchoolBooking
 */
final class SchoolBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institute_name' => $this->institute_name,
            'mechanographic_code' => $this->mechanographic_code,
            'ipa_office_code' => $this->ipa_office_code,
            'cig_code' => $this->cig_code,
            'cup_code' => $this->cup_code,
            'split_payment' => $this->split_payment,
            'visit_date' => $this->visit_date->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'workshops' => $this->workshopLines->map(fn (SchoolBookingWorkshop $line): array => [
                'workshop_id' => $line->workshop_id,
                'code' => $line->workshop?->code,
                'quantity_students' => $line->quantity_students,
            ])->values()->all(),
            'picnic_reserved' => $this->picnic_reserved,
            'picnic_seats' => $this->picnic_seats,
            'paying_students' => $this->paying_students,
            'disabled_students' => $this->disabled_students,
            'accompanying_teachers' => $this->accompanying_teachers,
            'free_teachers' => $this->free_teachers,
            'charged_teachers' => $this->charged_teachers,
            'total_amount' => (float) $this->total_amount,
            'vat_rate' => (float) $this->vat_rate,
            'status' => $this->status,
            'customer_contact_id' => $this->customer_contact_id,
            'fattura_invoice_id' => $this->fattura_invoice_id,
            'fattura_number' => $this->fattura_number,
            'invoiced_at' => $this->invoiced_at?->toIso8601String(),
        ];
    }
}
