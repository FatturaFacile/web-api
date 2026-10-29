<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DepreciationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_number' => $this->run_number,
            'period_code' => $this->period_code,
            'period_type' => $this->period_type,
            'ledger_type' => $this->ledger_type,
            'assets_processed_count' => (int) $this->assets_processed_count,
            'total_depreciation_amount' => (float) $this->total_depreciation_amount,
            'status' => $this->status,
            'executed_at' => $this->executed_at?->toIso8601String(),
        ];
    }
}
