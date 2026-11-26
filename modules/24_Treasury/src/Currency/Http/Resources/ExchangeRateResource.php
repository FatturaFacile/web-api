<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Resources;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateMapper;

/**
 * A rate as the API shows it: base and quote, the rate as a six-decimal string, the day as `Y-m-d`.
 *
 * @mixin ExchangeRate
 */
final class ExchangeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'base' => $this->resource->source,
            'quote' => $this->resource->target,
            'rate' => (string) $this->resource->rate,
            'rate_type' => $this->resource->rate_type,
            'source' => $this->resource->rate_source,
            'valid_on' => ExchangeRateMapper::day($this->resource->getRawOriginal('effective_date')),
            'is_derived' => (bool) $this->resource->is_derived,
            'is_contract' => $this->resource->rate_type === RateType::Contract->value,
            'version' => (int) $this->resource->version,
            'superseded_by' => $this->resource->superseded_by,
            'tenant_id' => $this->resource->tenant_id,
            'reason' => $this->resource->reason,
            'contract_reference' => $this->resource->contract_reference,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
