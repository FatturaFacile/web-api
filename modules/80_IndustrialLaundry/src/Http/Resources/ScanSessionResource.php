<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Support\ContactName;

/**
 * A roll cage scan session: the reads, what was accepted and refused, the weights, the alerts and the delivery note.
 *
 * @mixin ScanSession
 */
final class ScanSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_scan_id' => $this->external_scan_id,
            'gate_antenna_id' => $this->gate_antenna_id,
            'roll_cage_id' => $this->roll_cage_id,
            'roll_cage_barcode' => $this->roll_cage_barcode,
            'roll_cage_tare_weight_kg' => (string) $this->roll_cage_tare_weight_kg,
            'gross_weight_kg' => $this->gross_weight_kg !== null ? (string) $this->gross_weight_kg : null,
            'net_weight_kg' => $this->net_weight_kg !== null ? (string) $this->net_weight_kg : null,
            'direction' => $this->direction->value,
            'contact_id' => $this->contact_id,
            'customer' => $this->relationLoaded('customer') ? ContactName::summary($this->customer) : null,
            'read_epc_tags_list' => $this->read_epc_tags_list,
            'accepted_epc_tags' => $this->accepted_epc_tags ?? [],
            'rejected_reads' => $this->rejected_reads ?? [],
            'alerts' => $this->alerts ?? [],
            'raw_reads_count' => $this->raw_reads_count,
            'accepted_count' => $this->accepted_count,
            'rejected_count' => $this->rejected_count,
            'generated_ddt_id' => $this->generated_ddt_id,
            'generated_ddt_number' => $this->generated_ddt_number,
            'scanned_at' => $this->scanned_at->toIso8601String(),
        ];
    }
}
