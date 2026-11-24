<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Childcare\Models\ChildcareRecord;

/**
 * A childcare record as the API shows it to authorised staff: its attributes decrypted, decimals as strings.
 *
 * @mixin ChildcareRecord
 */
final class ChildcareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
