<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkOrder
 */
class WorkOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->attributesToArray())->except(['deleted_at', 'client_signature_path'])->all(),
            [
                'has_signature' => $this->client_signature_path !== null,
                'client' => $this->whenLoaded('client', fn () => [
                    'id' => $this->client->id,
                    'name' => $this->client->company_name ?: trim($this->client->first_name.' '.$this->client->last_name),
                ]),
                'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => $line->toArray())->all()),
            ]
        );
    }
}
