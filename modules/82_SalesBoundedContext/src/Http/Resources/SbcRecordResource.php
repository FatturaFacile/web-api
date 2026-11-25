<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified JSON resource wrapper for SalesBoundedContext Eloquent models.
 */
final class SbcRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
