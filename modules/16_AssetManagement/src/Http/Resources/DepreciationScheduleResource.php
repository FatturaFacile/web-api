<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\DepreciationSchedule;

/**
 * The depreciation charged to an asset book for a period. Amounts are decimal strings.
 *
 * @mixin DepreciationSchedule
 */
final class DepreciationScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'run_id' => $this->run_id,
            'ledger_type' => $this->ledger_type,
            'period_code' => $this->period_code,
            'period_type' => $this->period_type,
            'depreciation_method' => $this->depreciation_method,
            'opening_book_value' => $this->opening_book_value !== null ? (string) $this->opening_book_value : null,
            'depreciation_amount' => $this->depreciation_amount !== null ? (string) $this->depreciation_amount : null,
            'closing_book_value' => $this->closing_book_value !== null ? (string) $this->closing_book_value : null,
            'accumulated_depreciation' => $this->accumulated_depreciation !== null ? (string) $this->accumulated_depreciation : null,
            'units_produced' => $this->units_produced !== null ? (string) $this->units_produced : null,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
        ];
    }
}
