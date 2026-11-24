<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\DepreciationRun;

/**
 * A depreciation run of a period and ledger. Amounts are decimal strings.
 *
 * @mixin DepreciationRun
 */
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
            'assets_processed_count' => $this->assets_processed_count !== null ? (int) $this->assets_processed_count : null,
            'total_depreciation_amount' => $this->total_depreciation_amount !== null ? (string) $this->total_depreciation_amount : null,
            'status' => $this->status,
            'executed_at' => $this->executed_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'summary_metrics' => $this->summary_metrics,
        ];
    }
}
