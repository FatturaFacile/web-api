<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateTier;

/**
 * A step of the rebate scale.
 *
 * @mixin RebateTier
 */
final class TierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'threshold_amount' => $this->threshold_amount, 'rate_percent' => $this->rate_percent];
    }
}
