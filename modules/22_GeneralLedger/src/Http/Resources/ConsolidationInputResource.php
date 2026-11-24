<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An input of the consolidation (account mapping, acquisition with its adjustments and impairments, intragroup
 * transaction or dividend) as it is stored, without the owner tenant and the audit columns.
 *
 * @mixin Model
 */
final class ConsolidationInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_diff_key($this->resource->toArray(), array_flip(['tenant_id', 'deleted_at', 'created_at', 'updated_at']));
    }
}
