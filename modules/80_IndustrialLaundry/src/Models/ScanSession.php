<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;

/**
 * A roll cage passing a tunnel gate (RollCageRfidScanSession): the reads of the UHF reader, what survived the filter, the weights
 * and the delivery note (DDT) that was drawn up.
 *
 * @property string|null $external_scan_id Idempotency key sent by the reader middleware.
 * @property string $gate_antenna_id
 * @property string|null $roll_cage_id
 * @property string $roll_cage_barcode
 * @property string $roll_cage_tare_weight_kg
 * @property string|null $gross_weight_kg
 * @property string|null $net_weight_kg
 * @property ScanDirection $direction
 * @property string|null $contact_id
 * @property list<array<string, mixed>>|null $read_epc_tags_list
 * @property list<string>|null $accepted_epc_tags
 * @property list<array<string, mixed>>|null $rejected_reads
 * @property list<array<string, mixed>>|null $alerts
 * @property int $raw_reads_count
 * @property int $accepted_count
 * @property int $rejected_count
 * @property string|null $generated_ddt_id
 * @property string|null $generated_ddt_number
 * @property Carbon $scanned_at
 */
final class ScanSession extends BaseEnterpriseModel
{
    protected $table = 'ilr_scan_sessions';

    protected function casts(): array
    {
        return [
            'direction' => ScanDirection::class,
            'roll_cage_tare_weight_kg' => 'decimal:3',
            'gross_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'read_epc_tags_list' => 'array',
            'accepted_epc_tags' => 'array',
            'rejected_reads' => 'array',
            'alerts' => 'array',
            'raw_reads_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<RollCage, $this>
     */
    public function rollCage(): BelongsTo
    {
        return $this->belongsTo(RollCage::class, 'roll_cage_id');
    }
}
