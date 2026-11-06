<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Customs\Models\CustomsRecord;

/**
 * A customs record as the API shows it: its attributes, with decimals as strings, and the loaded relations.
 *
 * @mixin CustomsRecord
 */
final class CustomsRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'is_generic' => $this->resource->isGeneric()];
    }
}
