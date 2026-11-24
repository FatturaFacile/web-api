<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoShowcaseIngredientBook;

/**
 * @mixin GelatoShowcaseIngredientBook
 */
final class GelatoShowcaseBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'location_key', 'jurisdiction', 'version', 'payload', 'published_at']);
    }
}
