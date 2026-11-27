<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disposal slip of green waste (FIR / DDT in Italy, OTR movement slip in Switzerland) delivered to a composting facility.
 *
 * @property string $slip_number
 * @property string|null $site_id
 * @property string|null $route_schedule_id
 * @property string|null $route_stop_id
 * @property Carbon $disposal_date
 * @property string $waste_code
 * @property string|null $waste_description
 * @property string $green_waste_weight_kg
 * @property string|null $compost_facility_id
 * @property string|null $transporter_vehicle_plate
 * @property string|null $document_reference
 * @property string $disposal_cost
 * @property bool $is_billable_to_customer
 * @property string|null $invoice_id
 */
final class GreenWasteSlip extends BaseEnterpriseModel
{
    protected $table = 'green_waste_slips';

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'green_waste_weight_kg' => 'decimal:2',
            'disposal_cost' => 'decimal:2',
            'is_billable_to_customer' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GreenSpaceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(GreenSpaceSite::class, 'site_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function compostFacility(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'compost_facility_id');
    }
}
