<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TenderManagement\Models\CompetitorBenchmark;

/**
 * @mixin CompetitorBenchmark
 */
final class BenchmarkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tender_id' => $this->tender_id,
            'competitor_name' => $this->competitor_name,
            'contracting_authority_name' => $this->contracting_authority_name,
            'cpv_code' => $this->cpv_code,
            'jurisdiction' => $this->jurisdiction,
            'award_date' => $this->award_date?->toDateString(),
            'base_auction_amount' => $this->base_auction_amount,
            'awarded_amount' => $this->awarded_amount,
            'offered_discount_percentage' => $this->offered_discount_percentage,
            'technical_score' => $this->technical_score,
            'economic_score' => $this->economic_score,
            'total_score' => $this->total_score,
            'is_winner' => $this->is_winner,
            'source' => $this->source,
            'notes' => $this->notes,
        ];
    }
}
