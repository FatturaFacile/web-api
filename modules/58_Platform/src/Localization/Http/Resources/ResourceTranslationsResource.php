<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The translations of a master data record per active locale, with completeness metrics.
 *
 * @property array<string, mixed> $resource
 */
final class ResourceTranslationsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
