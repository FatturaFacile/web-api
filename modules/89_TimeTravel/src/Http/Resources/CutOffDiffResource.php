<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TimeTravel\Domain\ValueObjects\TimeTravelDiffComparison;

final class CutOffDiffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TimeTravelDiffComparison $comparison */
        $comparison = $this->resource;

        return $comparison->toArray();
    }
}
