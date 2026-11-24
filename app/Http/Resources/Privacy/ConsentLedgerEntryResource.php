<?php

declare(strict_types=1);

namespace App\Http\Resources\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ConsentLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
