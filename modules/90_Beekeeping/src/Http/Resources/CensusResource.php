<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Services\CensusService;

/**
 * @mixin CensusDeclaration
 */
final class CensusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'census_year' => $this->census_year,
            'country' => $this->country,
            'status' => $this->status,
            'window_start' => $this->window_start->toDateString(),
            'window_end' => $this->window_end->toDateString(),
            'total_hives' => $this->total_hives,
            'total_nuclei' => $this->total_nuclei,
            'snapshot' => $this->snapshot,
            'generated_at' => $this->generated_at->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submission_reference' => $this->submission_reference,
            'in_window' => app(CensusService::class)->isWithinWindow($this->resource),
        ];
    }
}
