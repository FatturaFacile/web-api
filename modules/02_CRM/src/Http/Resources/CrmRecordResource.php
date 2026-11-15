<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record of the CRM extension (territory, quota, partner, deal registration, MDF, CPQ, automation) as its columns, without
 * the tenant and the soft delete marker. Loaded relations and the computed figures a controller attached (attainment,
 * summary) are included.
 *
 * @mixin Model
 */
final class CrmRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->attributesToArray();
        unset($data['tenant_id'], $data['deleted_at']);

        foreach ($this->resource->getRelations() as $name => $relation) {
            $data[$name] = $relation instanceof Model ? (new self($relation))->toArray($request) : $relation?->map(static fn (Model $item): array => (new self($item))->toArray($request))->all();
        }

        return $data;
    }
}
