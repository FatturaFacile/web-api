<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\PeriodRate;

/**
 * @mixin PeriodRate
 */
final class PeriodRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => $this->group_id,
            'currency' => $this->currency,
            'group_currency' => $this->group_currency,
            'rate_type' => $this->rate_type,
            'period_end' => $this->period_end->toDateString(),
            'rate' => (string) $this->rate,
            'note' => $this->note,
        ];
    }
}
