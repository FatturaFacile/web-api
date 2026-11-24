<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the structured Side-by-Side Bid Comparison Matrix DTO.
 */
final class BidComparisonMatrixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $matrix */
        $matrix = $this->resource;

        return $matrix;
    }
}
