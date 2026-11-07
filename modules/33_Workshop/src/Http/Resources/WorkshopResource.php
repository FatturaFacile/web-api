<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workshop\Models\WorkshopRecord;

/**
 * A workshop record as the API shows it: its attributes, decimals as strings, and the loaded relations.
 *
 * @mixin WorkshopRecord
 */
final class WorkshopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
