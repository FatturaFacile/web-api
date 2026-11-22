<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer 360 summary: account identity, health and the cross-module KPIs.
 *
 * @property array<string, mixed> $resource
 */
final class Customer360Resource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account' => $this->resource['account'],
            'health' => $this->resource['health'],
            'kpis' => $this->resource['kpis'],
        ];
    }
}
