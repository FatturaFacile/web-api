<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\FieldService\ServiceReportBilling;
use Modules\ServiceManagement\FieldService\ServiceReportSeal;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use OpenApi\Attributes as OA;

/**
 * @mixin DigitalServiceReport
 */
#[OA\Schema(
    schema: 'FsmServiceReport',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'report_number', type: 'string'),
        new OA\Property(property: 'work_performed_summary', type: 'string'),
        new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'item', type: 'string'), new OA\Property(property: 'result', type: 'string', enum: ['pass', 'fail', 'na']), new OA\Property(property: 'notes', type: 'string', nullable: true)], type: 'object')),
        new OA\Property(property: 'labor_hours', type: 'string'),
        new OA\Property(property: 'travel_hours', type: 'string'),
        new OA\Property(property: 'travel_km', type: 'string'),
        new OA\Property(property: 'apply_callout_fee', type: 'boolean'),
        new OA\Property(property: 'meter_reading_hours', type: 'string', nullable: true),
        new OA\Property(property: 'meter_reading_cycles', type: 'integer', nullable: true),
        new OA\Property(property: 'customer_signer_name', type: 'string'),
        new OA\Property(property: 'customer_signer_role', type: 'string', nullable: true),
        new OA\Property(property: 'signature_svg', type: 'string', nullable: true),
        new OA\Property(property: 'signature_sha256_hash', type: 'string'),
        new OA\Property(property: 'signature_intact', type: 'boolean', description: 'false when the stored report no longer matches its seal'),
        new OA\Property(property: 'signed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'locale', type: 'string', enum: ['it-IT', 'it-CH', 'de-CH', 'fr-CH', 'en']),
        new OA\Property(property: 'has_pdf', type: 'boolean'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'billing', type: 'object', description: 'Priced lines; covered lines are charged 0.00'),
    ],
)]
final class DigitalServiceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'work_order_id' => (string) $this->work_order_id,
            'report_number' => (string) $this->report_number,
            'work_performed_summary' => (string) $this->work_performed_summary,
            'checklist' => array_values((array) $this->checklist_json),
            'labor_hours' => (string) $this->labor_hours,
            'travel_hours' => (string) $this->travel_hours,
            'travel_km' => (string) $this->travel_km,
            'apply_callout_fee' => (bool) $this->apply_callout_fee,
            'meter_reading_hours' => $this->meter_reading_hours,
            'meter_reading_cycles' => $this->meter_reading_cycles,
            'customer_signer_name' => (string) $this->customer_signer_name,
            'customer_signer_role' => $this->customer_signer_role,
            'signature_svg' => $this->signature_svg_data,
            'signature_sha256_hash' => (string) $this->signature_sha256_hash,
            'signature_intact' => ServiceReportSeal::verify($this->resource),
            'signed_at' => $this->signed_at->toIso8601String(),
            'gps_latitude' => $this->gps_latitude,
            'gps_longitude' => $this->gps_longitude,
            'photos' => array_values((array) $this->photo_attachments_json),
            'locale' => (string) $this->locale,
            'has_pdf' => $this->pdf_path !== null,
            'emailed_at' => $this->emailed_at?->toIso8601String(),
            'invoice_id' => $this->invoice_id === null ? null : (string) $this->invoice_id,
            'billing' => app(ServiceReportBilling::class)->breakdown($this->resource),
        ];
    }
}
