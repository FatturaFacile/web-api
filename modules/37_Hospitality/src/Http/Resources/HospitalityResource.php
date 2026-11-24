<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hospitality\Models\HospitalityRecord;

/**
 * A restaurant record as the API shows it: its attributes, decimals as strings, and the loaded relations.
 *
 * @mixin HospitalityRecord
 */
final class HospitalityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
