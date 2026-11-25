<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\EntryFeeDeferral;

/**
 * @mixin EntryFeeDeferral
 */
final class DeferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->period,
            'amount' => $this->amount,
            'recognized' => $this->recognized_at !== null,
            'recognized_at' => $this->recognized_at?->toIso8601String(),
        ];
    }
}
