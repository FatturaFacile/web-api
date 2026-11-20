<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource over a payload already shaped by an Academy query (progress, verification, dashboard): the shape of each is
 * documented by its OpenAPI schema.
 */
final class AcademyArrayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
