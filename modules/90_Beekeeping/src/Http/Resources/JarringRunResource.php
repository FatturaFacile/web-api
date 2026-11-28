<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\JarringRun;

/**
 * @mixin JarringRun
 */
final class JarringRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'extraction_batch_id' => $this->extraction_batch_id,
            'jar_lot_code' => $this->jar_lot_code,
            'jarred_on' => $this->jarred_on->toDateString(),
            'jar_format_g' => $this->jar_format_g,
            'jars_count' => $this->jars_count,
            'net_kg' => $this->net_kg,
            'best_before' => $this->best_before->toDateString(),
            'origin_country' => $this->origin_country,
            'warehouse_id' => $this->warehouse_id,
            'stock_movements' => $this->stock_movements ?? [],
        ];
    }
}
