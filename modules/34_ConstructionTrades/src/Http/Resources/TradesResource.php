<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ConstructionTrades\Models\TradesRecord;

/**
 * A construction trades record as the API shows it: its attributes, decimals as strings, and the loaded relations.
 *
 * @mixin TradesRecord
 */
final class TradesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'is_generic' => $this->resource->isGeneric()];
    }
}
