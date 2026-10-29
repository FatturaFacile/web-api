<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'opening_book_value' => (float) $this->opening_book_value,
            'depreciation_amount' => (float) $this->depreciation_amount,
            'closing_book_value' => (float) $this->closing_book_value,
            'accumulated_depreciation' => (float) $this->accumulated_depreciation,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
