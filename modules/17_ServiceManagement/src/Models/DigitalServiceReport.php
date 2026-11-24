<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The digital service report of a field work order (GH-202): what was done on site, the metrics that drive billing and
 * the customer signature, sealed by a SHA-256 hash over the signed content. One report per work order; `client_uuid`
 * makes the offline mobile sync idempotent.
 *
 * @property string $id
 * @property string $work_order_id
 * @property string $report_number
 * @property string $labor_hours
 * @property string $travel_hours
 * @property string $travel_km
 * @property bool $apply_callout_fee
 * @property string $signature_sha256_hash
 * @property string|null $pdf_path
 * @property string|null $invoice_id
 * @property Carbon $signed_at
 * @property array<int|string, mixed>|null $checklist_json
 * @property array<int|string, mixed>|null $photo_attachments_json
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $emailed_at
 */
final class DigitalServiceReport extends BaseEnterpriseModel
{
    protected $table = 'esm_digital_service_reports';

    protected function casts(): array
    {
        return [
            'checklist_json' => 'array',
            'photo_attachments_json' => 'array',
            'metadata' => 'array',
            'labor_hours' => 'decimal:2',
            'travel_hours' => 'decimal:2',
            'travel_km' => 'decimal:2',
            'apply_callout_fee' => 'boolean',
            'meter_reading_hours' => 'decimal:2',
            'meter_reading_cycles' => 'integer',
            'gps_latitude' => 'float',
            'gps_longitude' => 'float',
            'signed_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
