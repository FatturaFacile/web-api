<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Currency\Infrastructure\Persistence\EffectiveCurrencySettings;

/**
 * @mixin EffectiveCurrencySettings
 */
final class CurrencySettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
